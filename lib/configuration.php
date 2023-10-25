<?php 
namespace BizCuit\BillInput\Inotify;

function load_configuration (string $filename):array {
    if (!is_readable($filename)) {
        return [];
    }

    if(($conf = parse_ini_file($filename, true)) === false) {
        return [];
    }

    return $conf;
}

function verify_configuration (array &$conf):bool {
    
    if (!is_array($conf) || empty($conf)) { return false; }
    
    if (empty($conf['directory'])) { return false; }
    $conf['directory'] = realpath($conf['directory']);
    if (!is_dir($conf['directory']) || !is_readable($conf['directory'])) { return false; }

    if (empty($conf['archive'])) { return false; }
    $conf['archive'] = realpath($conf['archive']);
    if (!is_dir($conf['archive']) || !is_writable($conf['archive'])) { return false; }

    if (empty($conf['mysql'])) { return false; }
    if (empty($conf['mysql']['host']) && !empty($conf['mysql']['socket'])) {
        $conf['mysql']['host'] = 'localhost';
    }
    if (empty($conf['mysql']['host'])) { return false; }
    if (empty($conf['mysql']['user'])) { return false; }
    if (empty($conf['mysql']['password'])) { return false; }
    if (empty($conf['mysql']['database'])) { return false; }

    return true;
}