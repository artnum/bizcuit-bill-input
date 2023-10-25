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
use Matrix\Decomposition\QR;
use mysqli;

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
    $mysql = new mysqli($conf['mysql']['host'], $conf['mysql']['user'], $conf['mysql']['password'], $conf['mysql']['database'], $conf['mysql']['port'] ?? 3306, $conf['mysql']['socket'] ?? null);
    $DB = new DBMysql($mysql);
    $inotify = \inotify_init();
    $watch_descriptor = \inotify_add_watch($inotify, $conf['directory'], IN_CLOSE_WRITE | IN_ONLYDIR);
    while ($RUN) {
        $read = [$inotify];
        $write = [];
        $except = [];
        if (@stream_select($read, $write, $except, 2, null)) {
            $events = \inotify_read($inotify);

            foreach ($events as $event) {
                $file = $conf['directory'] . '/' . $event['name'];
                if (!file_exists($file)) { continue; }
                foreach(splitter($file) as $pdfstring) {
                    $hash = hash(HASH_FILE_ALGO, $pdfstring);
                    echo 'Processing string with hash : ' . $hash . PHP_EOL;
                    $error = '';
                    $data = read_qr_data($pdfstring, $error);
                    $bill = ['hash' => $hash, 'reference' => ''];
                    $client = [];
                    if (!$data) { echo "\tFailed : $error\n"; continue; }

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
                        $bill['reference'] = $swico->reference;
                    } else {
                        $bill['reference'] = (!empty($data[QRCH\RmtInf\Ref]) ? $data[QRCH\RmtInf\Ref] : $data[QRCH\AddInf\Ustrd]);
                    }
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
                            $carry[] = $item['day'] . ':' . $item['reduction'];
                            return $carry;
                        }, []));

                    }
                    $bill['duedate'] = clone $bill['date'];
                    $bill['duedate']->add(new DateInterval('P' . $day . 'D'));
                    

                    echo "\tCheck bill exists : ";
                    if ($DB->billExists($bill, $client)) { echo "\t\tyes, skip\n"; continue; }
                    echo "\t\tno\n";

                    echo "\tCheck qraddress exists : ";
                    $qraddress_id = $DB->qraddressExists($client);
                    if ($qraddress_id === false) {
                        echo "\t\tno\n";
                        $qraddress_id = $DB->qraddressCreate($client);
                    } else {
                        echo "\t\tyes $qraddress_id\n";
                    }

                    $archivePath = sprintf('%s/%s/%s', $conf['archive'], substr($hash, 0, 2), substr($hash, 2, 2));
                    @mkdir($archivePath, 0777, true);
                    $fileId = 0;
                    do {
                        $fileId++;
                    } while(file_exists($archivePath . '/' . $hash . '_' . sprintf('%03d', $fileId) . '.pdf'));
                    $bill['file'] = $hash . '_' . sprintf('%03d', $fileId);
                    file_put_contents($archivePath . '/' . $bill['file'] . '.pdf', $pdfstring);
                    $DB->factureCreate($bill, $qraddress_id);
                }
                unlink($file);
            }
        }
    }
    \inotify_rm_watch($inotify, $watch_descriptor);
    fclose($inotify);
}

return deamon($argv);
