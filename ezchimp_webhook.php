<?php

/*
**********************************************

      *** MailChimp Newsletter WebHook ***

WebHook for ezchimp addon module.

Author: Sunjith P S
License: GPLv3

Copyright AdMod Technologies Pvt Ltd
http://www.admod.com

**********************************************
*/

/* Config */
// Path to your WHMCS configuration file
$whmcs_configuration_file = "configuration.php";
$log_file = "ezchimp_webhook.log";
$debug = 0;
$dbh = null;

if (!file_exists($whmcs_configuration_file)) {
    write_log("No config file");
    exit;
}
require_once($whmcs_configuration_file);

if (!($dbh = mysql_connect($db_host, $db_username, $db_password))) {
    write_log("Could not connect to database server: ".mysql_error());
    exit;
}
if (!mysql_select_db($db_name)) {
    write_log("Could not select database: ".mysql_error());
    exit;
}

/* Get debug */
$query = "SELECT `value` FROM  `tbladdonmodules` WHERE `module` = 'ezchimp' AND `setting` = 'debug'";
if (!($result = mysql_query($query))) {
    write_log("Could not read debug level: ".mysql_error());
} else {
    $setting = mysql_fetch_assoc($result);
    if (($setting['value'] > 0) && ($setting['value'] < 10)) {
        $debug = $setting['value'];
    }
}

if ($debug > 4) {
    logActivity("ezchimp_webhook: POST - ".print_r($_POST, true));
}

if (isset($_POST['type']) && ('unsubscribe' == $_POST['type'])) {
    $query = "SELECT `cv`.`fieldid` AS `field_id`, `cv`.`relid` AS `rel_id` FROM `tblclients` AS `cl`";
    $query .= " JOIN `tblcustomfieldsvalues` AS `cv` ON `cl`.`id` = `cv`.`relid`";
    $query .= " JOIN `tblcustomfields` AS `cf` ON `cv`.`fieldid` = `cf`.`id`";
    $query .= " WHERE `cl`.`email` = '".mysql_real_escape_string($_POST['data']['email'])."' AND `cf`.`fieldoptions` LIKE '".mysql_real_escape_string($_POST['data']['list_id'])."%'";
    if ($debug > 4) {
        logActivity("ezchimp_webhook: Query - $query");
    }
    if (!($result = mysql_query($query))) {
        logActivity("Could not query client: ".mysql_error());
        exit;
    }
    $relid = 0;
    $fieldids = array();
    while ($row = mysql_fetch_assoc($result)) {
        $relid = $row['rel_id'];
        $fieldids[] = $row['field_id'];
        if ($debug > 2) {
            logActivity("ezchimp_webhook: rel_id: $relid, field_id: ".$row['field_id']);
        }
    }
    if ($relid > 0) {
        $query = "DELETE FROM `tblcustomfieldsvalues` WHERE `relid` = '".$relid."' AND `fieldid` IN ('".implode("', '", $fieldids)."')";
        if ($debug > 4) {
            logActivity("ezchimp_webhook: Query - $query");
        }
        if (!mysql_query($query)) {
            logActivity("ezchimp_webhook: Could not update client - ".mysql_error());
        }
    } else {
        logActivity("ezchimp_webhook: No client found");
    }
}

function write_log($mesg) {
    global $log_file;
    file_put_contents($log_file, date('r').": $mesg\n", FILE_APPEND);
}

function logActivity($mesg) {
    global $dbh;
    $query = "INSERT INTO `tblactivitylog` (`date`, `description`, `user`, `userid`, `ipaddr`) VALUES (NOW(), '".mysql_real_escape_string($mesg, $dbh)."', 'ezchimp', 0, '".$_SERVER['REMOTE_ADDR']."')";
    if (!mysql_query($query, $dbh)) {
        write_log("Could not write activity log: ".mysql_error());
    }
}
