<?php
namespace BizCuit\BillInput\Inotify;

declare(ticks = 1);

require('vendor/autoload.php');
require('lib/configuration.php');
require('lib/db.php');

use function BizCuit\Splitter\splitter;
use function BizCuit\SwissQR\read_qr_data;
use function BizCuit\SwissQR\decode_swicov1;

use BizCuit\SwissQR\QRCH;
use DateTime;
use DateInterval;
use mysqli;
use Exception;

const HASH_FILE_ALGO = 'xxh64';
$RUN = true;
$RESTART;
function sig_handler ($signal) {
    global $RUN;
    switch($signal) {
        case SIGINT:
        case SIGQUIT:
        case SIGTERM:
        case SIGHUP:
            echo 'Exit in progress ...'. PHP_EOL;
            $RUN = false;
            break;
    }
    return true;
}

pcntl_signal(SIGTERM, 'BizCuit\BillInput\Inotify\sig_handler');
pcntl_signal(SIGHUP, 'BizCuit\BillInput\Inotify\sig_handler');
pcntl_signal(SIGINT, 'BizCuit\BillInput\Inotify\sig_handler');
pcntl_signal(SIGQUIT, 'BizCuit\BillInput\Inotify\sig_handler');

function waitChilds (array $pids):array {
    $out = [];
    while(($towait = array_shift($pids))) {
        if (pcntl_waitpid($towait, $status, WNOHANG) === 0){
            $out[] = $towait;
            continue;
        }
        echo 'Child PID ' . $towait . ' exited' . PHP_EOL;
    }
    return $out;
}

function deamon ($argv) {
    global $RUN;
    
    $configuration_file = $argv[1];
    $conf = load_configuration($configuration_file);
    if (!verify_configuration($conf)) {
        echo 'Configuration error' . PHP_EOL;
        return;
    }

    $conf['directory'] = realpath($conf['directory']);
    if (!is_dir($conf['directory']) || !is_readable($conf['directory'])) {
        echo 'Directory not found or not readable' . PHP_EOL;
        return;
    }
    $inotify = \inotify_init();
    $watch_descriptor = \inotify_add_watch($inotify, $conf['directory'], IN_CLOSE_WRITE | IN_ONLYDIR);
    $pids = [];
    while ($RUN) {
        $read = [$inotify];
        $write = [];
        $except = [];
        if (@stream_select($read, $write, $except, 2, null)) {
            $events = \inotify_read($inotify);
            foreach ($events as $event) {
                $file = $conf['directory'] . '/' . $event['name'];
                echo 'Processing file : ' . $file . PHP_EOL;
                if (!file_exists($file) || !is_readable($file)) {
                    echo "\tFile not found or not readable :  $file\n";
                    continue; 
                }
                if (@mime_content_type($file) !== 'application/pdf') { 
                    echo "\tFile is not a PDF\n";
                    continue; 
                }
                /* fork, allow to process multiple file in parallel */
                $pid = pcntl_fork();
                if ($pid === 0) {
                    $pid = posix_getpid();
                    try {
                        $mysql = new mysqli($conf['mysql']['host'], $conf['mysql']['user'], $conf['mysql']['password'], $conf['mysql']['database'], $conf['mysql']['port'] ?? 3306, $conf['mysql']['socket'] ?? null);
                        $DB = new DBMysql($mysql);
                        pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGQUIT, SIGTERM, SIGHUP]);
                        foreach(splitter($file) as $pdfstring) {
                            $error = '';
                            $data = read_qr_data($pdfstring, $error);
                            if (!$data) { echo "[$pid]\tFailed : $error\n"; continue; }

                            $client = [];
                            $hCTX = hash_init(HASH_FILE_ALGO);
                            array_map(function ($line) use ($hCTX) { hash_update($hCTX, $line); }, $data);
                            $hash = hash_final($hCTX);
                            $bill = ['hash' => $hash, 'reference' => ''];

                            $client['ide'] = '';
                            $client['type'] = $data[QRCH\Cdtr\AdrTp];
                            $client['iban'] = $data[QRCH\CdtrInf\IBAN];
                            $client['name'] = $data[QRCH\Cdtr\Name];
                            $client['street'] = $data[QRCH\Cdtr\StrtNmOrAdrLine1];
                            $client['number'] = $data[QRCH\Cdtr\BldgNbOrAdrLine2];
                            $client['postcode'] = $data[QRCH\Cdtr\PstCd];
                            $client['town'] = $data[QRCH\Cdtr\TwnNm];
                            $client['country'] = $data[QRCH\Cdtr\Ctry];
                            
                            $bill['amount'] = floatval($data[QRCH\CcyAmt\Amt]);
                            $bill['currency'] = strtolower($data[QRCH\CcyAmt\Ccy]);
                            $bill['qrdata'] = implode("\n", $data);
                            $swico = decode_swicov1($data);
                            if (isset($swico->date)) {
                                $bill['date'] = $swico->date;
                            } else {
                                $bill['date'] = new DateTime();
                            }
                            if (isset($swico->reference)) {
                                $bill['number'] = $swico->reference;
                            }
                            $bill['reference'] = (!empty($data[QRCH\RmtInf\Ref]) ? $data[QRCH\RmtInf\Ref] : $data[QRCH\AddInf\Ustrd]);

                            if (isset($swico->ide)) {
                                $client['ide'] = sprintf('CHE-%s.%s.%s', substr($swico->ide, 0, 3), substr($swico->ide, 3, 3), substr($swico->ide, 6, 3));
                            }
                            $day = 30;
                            if (isset($swico->conditions)) {
                                foreach ($swico->conditions as $condition) {
                                    if ($condition['reduction'] === 0) {
                                        $day = $condition['day'];
                                        break;
                                    }
                                }
                                $bill['conditions'] = implode(';', array_reduce($swico->conditions, function ($carry, $item) {
                                    $carry[] = $item['reduction'] . ':' . $item['day'];
                                    return $carry;
                                }, []));
                            } else {
                                $bill['conditions'] = '0:30';
                            }
                            $bill['duedate'] = clone $bill['date'];
                            $bill['duedate']->add(new DateInterval('P' . $day . 'D'));
                            
                            $bill = $DB->normalizeBillData($bill);
                            $client = $DB->normalizeClientData($client);

                            echo "[$pid]\tCheck bill exists : ";
                            if ($DB->billExists($bill, $client)) { echo "\t\tyes, skip\n"; continue; }
                            echo "\t\tno\n";

                            echo "[$pid]\tCheck qraddress exists : ";
                            $qraddress_id = $DB->qraddressExists($client);
                            if ($qraddress_id === false) {
                                $qraddress_id = $DB->qraddressCreate($client);
                                echo "\tno, created ID : $qraddress_id\n";
                            } else {
                                echo "\tyes, ID : $qraddress_id\n";
                            }

                            if ($conf['pdfarchive'] || $conf['b64copy']) {
                                $archivePath = sprintf('%s/%s/%s', $conf['archive'], substr($hash, 0, 2), substr($hash, 2, 2));
                                @mkdir($archivePath, 0777, true);
                                $fileId = 0;
                                do {
                                    $fileId++;
                                } while(file_exists($archivePath . '/' . $hash . '_' . sprintf('%03d', $fileId) . '.pdf'));
                                $bill['file'] = $hash . '_' . sprintf('%03d', $fileId);
                                if ($conf['pdfarchive']) { file_put_contents($archivePath . '/' . $bill['file'] . '.pdf', $pdfstring); }
                                /* more and more of those data are transmitted over the www, in some case a copy in base64 is usefull */
                                if ($conf['b64copy']) { file_put_contents($archivePath . '/' . $bill['file'] . '.pdf.b64', base64_encode($pdfstring)); }
                            }
                            $DB->factureCreate($bill, $qraddress_id);
                        }
                        unlink($file);
                        exit(0);
                    } catch (Exception $e) {
                        echo "[$pid]\tException : " . $e->getMessage() . PHP_EOL;
                        exit(1);
                    }
                } else {
                    echo 'Forked child PID ' . $pid . PHP_EOL;
                    $pids[] = $pid;
                }
            }
        }
        /* cleaning up childs each time we can */
        $pids = waitChilds($pids);
    }
    \inotify_rm_watch($inotify, $watch_descriptor);
    fclose($inotify);

    echo 'Waiting for childs to exit ...' . PHP_EOL;
    do {
        $pids = waitChilds($pids);
        /* wait for child to die */
        sleep(1);
    } while (count($pids) > 0);
    
}

return deamon($argv);
