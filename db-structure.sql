CREATE TABLE `facture` (
  `facture_id` int(11) NOT NULL AUTO_INCREMENT,
  `facture_reference` char(64) NOT NULL DEFAULT '',
  `facture_currency` char(3) NOT NULL DEFAULT 'chf',
  `facture_date` char(32) NOT NULL,
  `facture_duedate` char(32) NOT NULL,
  `facture_indate` char(32) NOT NULL,
  `facture_amount` float DEFAULT 0,
  `facture_type` int(11) DEFAULT 1,
  `facture_qrdata` varchar(997) DEFAULT '',
  `facture_person` text NOT NULL,
  `facture_comment` char(200) DEFAULT '',
  `facture_deleted` int(11) DEFAULT 0,
  `facture_extid` varchar(38) NOT NULL DEFAULT '',
  `facture_hash` char(16) NOT NULL DEFAULT '',
  `facture_file` char(20) NOT NULL DEFAULT '',
  `facture_conditions` varchar(30) DEFAULT '',
  `facture_qraddress` INT(10) UNSIGNED NULL,
  `facture_state` enum('INCOMING','OPEN','SENT','PAID','DELETED') NOT NULL DEFAULT 'INCOMING',
  `facture_remainder` TINYINT UNSIGNED DEFAULT 0,
  `facture_number` VARCHAR(140) DEFAULT '',
  PRIMARY KEY (`facture_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE INDEX `idxFactureState` ON `facture`(`facture_state`);

CREATE TABLE `qraddress` (
    `qraddress_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `qraddress_type` CHAR(1) NOT NULL,
    `qraddress_name` VARCHAR(70) NOT NULL,
    `qraddress_street` VARCHAR(70) NOT NULL DEFAULT '',
    `qraddress_number` VARCHAR(70) NOT NULL DEFAULT '',
    `qraddress_postcode` VARCHAR(16) NOT NULL DEFAULT '',
    `qraddress_town` VARCHAR(35) NOT NULL DEFAULT '',   
    `qraddress_iban` CHAR(21) NOT NULL,
    `qraddress_country` CHAR(2) NOT NULL,
    `qraddress_ide` VARCHAR(15) NOT NULL DEFAULT '',
    `qraddress_extid` VARCHAR(160) NOT NULL DEFAULT '',
    PRIMARY KEY (`qraddress_id`)
 ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;