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

    function __destruct () {
        $this->mysql->close();
    }

    /* normalize function are in DB class as it the way we want them in db */
    function normalizeClientData (array $client): array {
        $client['country'] = strtoupper($client['country']);
        $client['iban'] = str_replace(' ', '', strtoupper($client['iban']));
        return $client;
    }

    function normalizeBillData (array $bill):array {
        $bill['amount'] = (float)str_replace(',', '.', $bill['amount']);
        $bill['currency'] = strtoupper($bill['currency']);
        return $bill;
    }

    /* this need normalized data */
    function billExists (array $bill, array $client):false|int {
        try {
            /* amount comparison don't work with x = y, so we use a small 
               delta as swiss bill are limited to .01 steps
             */
            $query = 'SELECT 
                    facture_id, facture_amount 
                FROM facture
                LEFT JOIN qraddress ON facture_qraddress = qraddress_id
                WHERE 
                    (facture_amount - ?) < 0.01
                    AND LOWER(facture_currency) = ?
                    AND qraddress_type = ?
                    AND qraddress_name = ?
                    AND qraddress_country = ?';
            $stmt = $this->mysql->prepare($query);
            $stmt->bind_param(
                'dssss',
                $bill['amount'],
                $bill['currency'],
                $client['type'],
                $client['name'],
                $client['country']
            );
            $stmt->execute();
            $factureId = 0;
        
            $stmt->bind_result($factureId, $facture_amount);
            if (!$stmt->fetch()) {
                return false;
            }
            return $factureId;
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