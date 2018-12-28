<?php

/*
**********************************************

      *** MailChimp Newsletter WebHook ***

WebHook for ezchimp addon module.

Author: Sunjith P S
License: GPLv3

Copyright AdMod Technologies Pvt Ltd
www.admod.com www.supportmonk.com www.ezeelogin.com

**********************************************
*/

/* Config */
// Path to your WHMCS configuration file
$whmcs_configuration_file = "configuration.php";
$log_file = "ezchimp_webhook.log";
$debug = 0;
$dbh = null;

function write_log($mesg) {
    global $log_file;
    file_put_contents($log_file, date('r').": $mesg\n", FILE_APPEND);
}

function logActivity($mesg) {
    global $dbh;

    try {
        $query = $dbh->prepare("INSERT INTO `tblactivitylog` (`date`, `description`, `user`, `userid`, `ipaddr`) VALUES (NOW(), :mesg, 'ezchimp', 0, :remote_addr)");
        $query_params = [':mesg' => $mesg, ':remote_addr' => $_SERVER['REMOTE_ADDR']];
        $query->execute($query_params);
        $query->closeCursor();
    } catch (PDOException $e) {
        write_log("Could not write activity log: " . $e->getMessage());
    }
}

if (!file_exists($whmcs_configuration_file)) {
    write_log("No config file");
    exit;
}
require_once($whmcs_configuration_file);

try {
    $dbh = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_username, $db_password);
} catch (PDOException $e) {
    write_log("Could not connect to database: " . $e->getMessage());
    exit;
}
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbh->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

/* Get debug */
try {
    $query = $dbh->prepare("SELECT `value` FROM `tbladdonmodules` WHERE `module` = 'ezchimp' AND `setting` = 'debug'");
    $query->execute();
    $setting = $query->fetch(PDO::FETCH_ASSOC);
    if (($setting['value'] > 0) && ($setting['value'] < 10)) {
        $debug = $setting['value'];
    }
    $query->closeCursor();
} catch (PDOException $e) {
    write_log("Could not read debug level: " . $e->getMessage());
}

if ($debug > 4) {
    logActivity("ezchimp_webhook: POST - " . print_r($_POST, true));
}

if (isset($_POST['type']) && ('unsubscribe' == $_POST['type'])
    && isset($_POST['data']) && isset($_POST['data']['email']) && isset($_POST['data']['list_id'])) {
    $query_str = "SELECT `cv`.`fieldid` AS `field_id`, `cv`.`relid` AS `rel_id` FROM `tblclients` AS `cl`";
    $query_str .= " JOIN `tblcustomfieldsvalues` AS `cv` ON `cl`.`id` = `cv`.`relid`";
    $query_str .= " JOIN `tblcustomfields` AS `cf` ON `cv`.`fieldid` = `cf`.`id`";
    $query_str .= " WHERE `cl`.`email` = :email AND `cf`.`fieldoptions` LIKE :list_id";
    $query_params = [ ':email' => $_POST['data']['email'], ':list_id' => $_POST['data']['list_id'] . '%' ];
    if ($debug > 4) {
        logActivity("ezchimp_webhook: Unsubscribe Query - $query_str");
    }
    
    try {
        $query = $dbh->prepare($query_str);
    } catch (PDOException $e) {
        write_log("Could not prepare query: " . $e->getMessage());
        exit;
    }

    try {
        $query->execute($query_params);
    } catch (PDOException $e) {
        write_log("Could not execute query: " . $e->getMessage());
        exit;
    }
    
    $relid = 0;
    $fieldIds = [];
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $relid = $row['rel_id'];
        $fieldIds[] = $row['field_id'];
        if ($debug > 2) {
            logActivity("ezchimp_webhook: rel_id: $relid, field_id: ".$row['field_id']);
        }
    }
    $query->closeCursor();

    if ($relid > 0) {
        try {
            $in  = str_repeat('?,', count($fieldIds) - 1) . '?';
            $query_str = "DELETE FROM `tblcustomfieldsvalues` WHERE `relid` = ? AND `fieldid` IN ($in)";
            if ($debug > 4) {
                logActivity("ezchimp_webhook: tblcustomfieldsvalues Query - $query_str");
            }
            $query = $dbh->prepare($query_str);
            $query_params = $fieldIds;
            array_unshift($query_params, $relid);
            $query->execute($query_params);
            $query->closeCursor();
        } catch (PDOException $e) {
            logActivity("ezchimp_webhook: Could not update client - " . $e->getMessage());
        }
    } else {
        logActivity("ezchimp_webhook: No client found");
    }
}
