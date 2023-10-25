<?php
namespace BizCuit\BillInput\Inotify;

use Exception;
use mysqli;
use DateTime;

class DBMysql {
    protected $mysql; 
    function __construct (mysqli $mysql) {
        $this->mysql = $mysql;
    }

    function billExists (array $bill, array $client):bool {
        try {
            $query = 'SELECT 
                    facture_id, facture_amount, facture_currency,
                    qraddress_type, qraddress_name, qraddress_country
                FROM facture 
                LEFT JOIN qraddress ON facture_qraddress = qraddress_id
                WHERE facture_hash = ?';
            $stmt = $this->mysql->prepare($query);
            $stmt->bind_param('s', $bill['hash']);
            $stmt->execute();
            $facture = ['id' => 0, 'amount' => 0, 'currency' => ''];
            $qraddress = ['type' => '', 'name' => '', 'country' => ''];
            $stmt->bind_result(
                $facture['id'],
                $facture['amount'],
                $facture['currency'], 
                $qraddress['type'],
                $qraddress['name'],
                $qraddress['country']);
            if (!$stmt->fetch()) {
                return false;
            }
            if (floatval($bill['amount']) !== floatval($facture['amount'])) {
                return false;
            }
            if (strtolower($bill['currency']) !== strtolower($facture['currency'])) {
                return false;
            }
            if (strtolower($client['type']) !== strtolower($qraddress['type'])) {
                return false;
            }
            if (strtolower($client['name']) !== strtolower($qraddress['name'])) {
                return false;
            }
            if (strtolower($client['country']) !== strtolower($qraddress['country'])) {
                return false;
            }
            return true;
        } catch (Exception $e) {
            throw new Exception('Mysqli error::billExists', 0, $e);
        }
    }

    function qraddressExists (array $client):int|false {
        try {
            $query = 'SELECT
                    qraddress_id
                FROM qraddress
                WHERE 
                    qraddress_iban = ? AND qraddress_type = ? AND qraddress_name = ? AND qraddress_country = ?';
            
            $stmt = $this->mysql->prepare($query);
            $stmt->bind_param('ssss', $client['iban'], $client['type'], $client['name'], $client['country']);
            $stmt->execute();
            $id = false;
            $stmt->bind_result($id);
            if (!$stmt->fetch()) {
                return false;
            }
            return $id;
        } catch (Exception $e) {
            throw new Exception('Mysqli error::qraddressExists', 0, $e);
        }
    }

    function qraddressCreate (array $client):int|false {
        try {
            $query = 'INSERT INTO qraddress (
                qraddress_type, qraddress_name, qraddress_street, qraddress_number,
                qraddress_postcode, qraddress_town, qraddress_country, qraddress_iban,
                qraddress_ide
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $this->mysql->prepare($query);
            $stmt->bind_param(
                'sssssssss',
                $client['type'],
                $client['name'],
                $client['street'],
                $client['number'],
                $client['postcode'],
                $client['town'],
                $client['country'],
                $client['iban'],
                $client['ide']);
            if(!$stmt->execute()) { return false; }
            return (int)$stmt->insert_id;
        } catch (Exception $e) {
            throw new Exception('Mysqli error::qraddressCreate', 0, $e);
        }
    }

    const DEBITOR = 1;
    const CREDITOR = 2;
    const CRED_SCORE = 3;
    const COMPENSATION = 4;

    function factureCreate (array $facture, int $qraddress, int $type = DBMysql::CREDITOR):int|false {
        try {
            $now =  (new DateTime())->format('Y-m-d H:i:s');
            $query = 'INSERT INTO facture (
                facture_amount, facture_currency, facture_qraddress, facture_hash,
                facture_qrdata, facture_date, facture_duedate, facture_reference,
                facture_conditions, facture_file, facture_type, facture_indate
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $this->mysql->prepare($query);
            $date = $facture['date']->format('Y-m-d');
            $duedate = $facture['duedate']->format('Y-m-d');
            $stmt->bind_param(
                'dsisssssssis',
                $facture['amount'],
                $facture['currency'],
                $qraddress,
                $facture['hash'],
                $facture['qrdata'],
                $date,
                $duedate,
                $facture['reference'],
                $facture['conditions'],
                $facture['file'],
                $type,
                $now);
            if (!$stmt->execute()) { return false; }
            return (int)$stmt->insert_id;
        } catch (Exception $e) {
            throw new Exception('Mysqli error::factureCreate', 0, $e);
        }
    }
}