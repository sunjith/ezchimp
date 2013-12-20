<?php
/*
**********************************************

      *** MailChimp Newsletter Hook ***

Hooks for ezchimp addon module.

For more info, please refer to the hooks docs
 @   http://wiki.whmcs.com/Hooks

Author: Sunjith P S
License: GPLv3

Copyright AdMod Technologies Pvt Ltd
http://www.admod.com

**********************************************
*/

$debug = 0;
$config = $settings = array();

/**
 * Return ezchimp config
 */
function _ezchimp_get_config() {
    global $debug;

    $config = array();
    $result = select_query('tbladdonmodules', 'setting, value', array('module' => 'ezchimp'));
    while ($row = mysql_fetch_assoc($result)) {
        $config[$row['setting']] = $row['value'];
    }
    mysql_free_result($result);
    if (isset($config['debug'])) {
        $debug = intval($config['debug']);
    }
    if ($debug > 0) {
        logActivity("_ezchimp_get_config: module config - ".print_r($config, true));
    }
    return $config;
}

/**
 * Return ezchimp settings
 */
function _ezchimp_get_settings() {
    global $debug;

    $settings = array();
    $result = select_query('mod_ezchimp', 'setting, value');
    while($row = mysql_fetch_assoc($result)) {
        $settings[$row['setting']] = $row['value'];
    }
    mysql_free_result($result);
    if ($debug > 0) {
        logActivity("_ezchimp_get_settings: ".print_r($settings, true));
    }
    return $settings;
}
/**
 * Function for calling MailChimp API
 *
 * @param string
 * @param string
 * @param string
 * @param string
 */
function _ezchimp_call_mailchimp_api($method, $params) {
    global $debug;

    $payload = json_encode($params);
    $parts = explode('-', $params['apikey']);
    $dc = $parts[1];
    $submit_url = "http://$dc.api.mailchimp.com/1.3/?method=$method";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $submit_url);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, urlencode($payload));
    curl_setopt($ch, CURLOPT_USERAGENT, "ezchimp");
    curl_setopt($ch, CURLOPT_TIMEOUT, 100);
    $retval = curl_exec($ch);
    curl_close($ch);

    if ($debug > 0) {
        logActivity("_ezchimp_call_mailchimp_api: URL - $submit_url, Payload - ".htmlentities($payload).", Response - ".htmlentities($retval));
    }
    return json_decode($retval);

}

/**
 * Initialize global variables
 */
function _ezchimp_hook_init() {
    global $config, $settings;

    $config = _ezchimp_get_config();
    $settings = _ezchimp_get_settings();
}


/**
 * Function for WHMCS AcceptOrder hook
 * Mailing list subscription based on
 * ordered product(s)
 *
 * @param array
 */
function ezchimp_hook_order($vars)
{
    global $debug, $settings;

    _ezchimp_hook_init();

    if ($debug > 0) {
        logActivity("ezchimp_hook_order: vars - " . print_r($vars, true));
    }
    $order_id = $vars['orderid'];
    $groupings = unserialize($settings['groupings']);
    $groupings1 = unserialize($settings['unsubscribe_groupings']);
    $errors = $client = $subscriptions = $subscriptions1 = $subscriptions2 = $subscriptions3 = $sub = $sub_list = $productgroup_names = array();

    if ($debug > 0) {
        logActivity("ezchimp_hook_order: groupings - " . print_r($groupings, true));
    }
    if ($debug > 0) {
        logActivity("ezchimp_hook_order: groupings1 - " . print_r($groupings1, true));
    }
    /* Check ordered domains if domains grouping available */
    if (!empty($groupings['Domains']) || !empty($groupings1['Domains'])) {
        $result_d = select_query('tbldomains', 'userid', array('orderid' => $order_id));
        if ($domain = mysql_fetch_assoc($result_d)) {
            $productgroup_names['Domains'] = 1;
            $client_id = $domain['userid'];
        }
    }
    /* Check the ordered modules */
    $result1 = select_query('tblhosting', 'userid, packageid', array('orderid' => $order_id));
    while ($module = mysql_fetch_assoc($result1)) {
        $product_id = $module['packageid'];
        $client_id = $module['userid'];
        $result2 = select_query('tblproducts', 'gid', array('id' => $product_id));
        if ($product = mysql_fetch_assoc($result2)) {
            $productgroup_id = $product['gid'];
            $result3 = select_query('tblproductgroups', 'name', array('id' => $productgroup_id));
            if ($productgroup = mysql_fetch_assoc($result3)) {
                $productgroup_names[$productgroup['name']] = 1;
            } else {
                $errors[] = "Could not get product group name: $productgroup_id ($product_id)";
            }
        } else {
            $errors[] = "Could not get product group ID: $product_id";
        }
    }
    if ($debug > 0) {
        logActivity("ezchimp_hook_order: clientid - $client_id");
    }
    if ('' != $client_id) {
        $sub = _ezchimp_get_listgroup_subscriptions($client_id);
        if ($debug > 0) {
            logActivity("ezchimp_hook_order: sublist2 - " . print_r($sub, true));
        }
        foreach ($productgroup_names as $productgroup_name => $one) {
            if ((!empty($groupings[$productgroup_name])) || (!empty($groupings1[$productgroup_name]))) {
                /* Retrieve client details */
                if (empty($client)) {
                    $result4 = select_query('tblclients', 'firstname, lastname, email', array('id' => $client_id));
                    $client = mysql_fetch_assoc($result4);
                }
                if ($debug > 0) {
                    logActivity("ezchimp_hook_order: client - " . print_r($client, true));
                }
                $email_type = $settings['default_format'];
                $firstname = $client['firstname'];
                $lastname = $client['lastname'];
                $email = $client['email'];
                if (!empty($client)) {
                    foreach ($groupings[$productgroup_name] as $list_id1 => $list_groupings) {
                        if (!empty($list_groupings)) {
                            if ($debug > 0) {
                                logActivity("ezchimp_hook_order: subscription group1 - ".print_r($list_groupings, true));
                            }
                            foreach ($list_groupings as $maingroups => $groups) {
                                foreach ($groups as $grps) {
                                    $query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306 AND `fieldname`='" . mysql_real_escape_string($grps) . "'";
                                    $result = mysql_query($query);
                                    $row = mysql_fetch_assoc($result);
                                    $field_id = $row['id'];
                                    if ($debug > 0) {
                                        logActivity("ezchimp_hook_order: subscription field1 - " . print_r($row['id'], true));
                                    }
                                    mysql_free_result($result);
                                    $query = "SELECT DISTINCT `relid` FROM `tblcustomfieldsvalues` WHERE `fieldid` = $field_id AND `value`='on'";
                                    $result = mysql_query($query);
                                    $subscribed1 = array();
                                    while ($row = mysql_fetch_assoc($result)) {
                                        $subscribed1[$row['relid']] = 1;
                                    }
                                    mysql_free_result($result);
                                    $query = "SELECT DISTINCT `relid` FROM `tblcustomfieldsvalues` WHERE `fieldid` = $field_id AND `value`=''";
                                    $result = mysql_query($query);
                                    $subscribed2 = array();
                                    while ($row = mysql_fetch_assoc($result)) {
                                        $subscribed2[$row['relid']] = 1;
                                    }
                                    mysql_free_result($result);
                                    if (isset($subscribed2[$client_id])) {
                                        if ($debug > 2) {
                                            logActivity("ezchimp_output: subscribion update1 - ");
                                        }
                                        $query = "UPDATE `tblcustomfieldsvalues` SET `value`='on' where `relid`=$client_id AND `fieldid`=$field_id";
                                        mysql_query($query);
                                    } else if (!isset($subscribed1[$client_id])) {
                                        if ($debug > 2) {
                                            logActivity("ezchimp_output: subscribed insert1 - " . print_r($subscribed1, true));
                                        }
                                        $query = "INSERT INTO `tblcustomfieldsvalues` (`fieldid`, `relid`, `value`) VALUES ($field_id, $client_id, 'on')";
                                        mysql_query($query);
                                    } else {
                                        if ($debug > 2) {
                                            logActivity("ezchimp_output: list subscribed  - " );
                                        }
                                    }
                                }
                            }
                            if (!(is_array($list_groupings))) {
                                if ($debug > 0) {
                                    logActivity("_ezchimp_get_client_subscriptions: empty main group - ".print_r($subscriptions, true));
                                }
                                $query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306 AND `fieldoptions`='" . mysql_real_escape_string($list_id1) . "'";
                                $result = mysql_query($query);
                                $row = mysql_fetch_assoc($result);
                                $field_id = $row['id'];
                                if ($debug > 0) {
                                    logActivity("ezchimp_hook_order: subscription field3 - " . $row['id']);
                                }
                                mysql_free_result($result);
                                $query = "SELECT DISTINCT `relid` FROM `tblcustomfieldsvalues` WHERE `fieldid` = $field_id AND `value`='on'";
                                $result = mysql_query($query);
                                $subscribed1 = array();
                                while ($row = mysql_fetch_assoc($result)) {
                                    $subscribed1[$row['relid']] = 1;
                                }
                                mysql_free_result($result);
                                $query = "SELECT DISTINCT `relid` FROM `tblcustomfieldsvalues` WHERE `fieldid` = $field_id AND `value`=''";
                                $result = mysql_query($query);
                                $subscribed2 = array();
                                while ($row = mysql_fetch_assoc($result)) {
                                    $subscribed2[$row['relid']] = 1;
                                }
                                mysql_free_result($result);
                                if (isset($subscribed2[$client_id])) {
                                    if ($debug > 2) {
                                        logActivity("ezchimp_output: subscribion update3 - ");
                                    }
                                    $query = "UPDATE `tblcustomfieldsvalues` SET `value`='on' where `relid`=$client_id AND `fieldid`=$field_id";
                                    mysql_query($query);
                                } else if (!isset($subscribed1[$client_id])) {
                                    if ($debug > 2) {
                                        logActivity("ezchimp_output: subscribed insert3 - " . print_r($subscribed1, true));
                                    }
                                    $query = "INSERT INTO `tblcustomfieldsvalues` (`fieldid`, `relid`, `value`) VALUES ($field_id, $client_id, 'on')";
                                    mysql_query($query);
                                } else {
                                    if ($debug > 2) {
                                        logActivity("ezchimp_output: list subscribed3  - " );
                                    }
                                }

                            }
                        }
                    }
                    foreach ($groupings[$productgroup_name] as $list_id1 => $list_groupings) {
                        if (!empty($list_groupings)) {
                            if ($debug > 0) {
                                logActivity("ezchimp_hook_order: subscription group1 -$list_groupings ");
                            }
                            foreach ($list_groupings as $maingroups => $groups) {
                                foreach ($sub as $subgrp => $subgrps) {
                                    if (!($subgrps['unsubscribe'] == 1)) {
                                        foreach ($subgrps['grouping'] as $maingroup) {
                                            $subscription_groupings = array();
                                            $groups_str = '';
                                            $grps3 = '';
                                            if ($debug > 0) {
                                                logActivity("ezchimp_hook_order: sub2 - " . print_r($maingroup['groups'], true));
                                            }
                                            if (!(strcmp($maingroups, $maingroup['name']))) {
                                                if (!empty($maingroup['groups'])) {
                                                    $grps1 = explode(",", $maingroup['groups']);
                                                    $grps2 = array_unique($grps1);
                                                    $grps3 = implode(',', $grps2);
                                                    if ($debug > 0) {
                                                        logActivity("ezchimp_hook_order: subscribtion scheck - " . print_r($grps3, true));
                                                    }
                                                    $groups_str .= ($grps3) . ',';
                                                }
                                                if ('' != $groups_str) {
                                                    $groups_str = substr($groups_str, 0, -1);
                                                    $subscription_groupings[] = array('name' => $maingroups, 'groups' => $groups_str);
                                                    if ($debug > 0) {
                                                        logActivity("ezchimp_hook_order: subscription case1 - " . print_r($maingroups, true));
                                                    }
                                                    if ($debug > 0) {
                                                        logActivity("ezchimp_hook_order: subscription grp1 - " . print_r($groups_str, true));
                                                    }
                                                    $subscriptions[] = array('list' => $subgrps['list'], 'grouping' => $subscription_groupings);
                                                }
                                                if ($debug > 0) {
                                                    logActivity("ezchimp_hook_order: subscription grps1 - " . print_r($groups_str, true));
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            if (!(is_array($list_groupings))) {
                                $subscription_groupings1 = array();
                                $subscriptions[] = array('list' => $list_groupings, 'grouping' => $subscription_groupings1);
                                if ($debug > 0) {
                                    logActivity("_ezchimp_get_client_subscriptions: single prodname -". print_r($subscriptions, true));
                                }
                                if ($debug > 0) {
                                    logActivity("_ezchimp_get_client_subscriptions: listid - ". print_r($list_groupings, true));
                                }
                            }
                        }
                        foreach ($subscriptions as $subscription) {
                            _ezchimp_mailchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type);
                        }
                    }
                    foreach($groupings1[$productgroup_name] as $list_id2 => $list_unsub){
                        if (!empty($list_unsub)) {
                            if ($debug > 0) {
                                logActivity("ezchimp_hook_order: unsubscription1 group1 - ".print_r($list_unsub, true));
                            }
                            foreach ($list_unsub as  $groups) {
                                foreach($groups as $grps){
                                    $query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306 AND `fieldname`='" . mysql_real_escape_string($grps) . "'";
                                    $result = mysql_query($query);
                                    $row = mysql_fetch_assoc($result);
                                    $field_id = $row['id'];
                                    if ($debug > 0) {
                                        logActivity("ezchimp_hook_order: unsubscription1 field3 - " . print_r($row['id'], true));
                                    }
                                    mysql_free_result($result);
                                    $query = "SELECT DISTINCT `relid` FROM `tblcustomfieldsvalues` WHERE `fieldid` = $field_id AND `value`='on'";
                                    $result = mysql_query($query);
                                    $subscribed = array();
                                    while ($row = mysql_fetch_assoc($result)) {
                                        $subscribed[$row['relid']] = 1;
                                    }
                                    mysql_free_result($result);
                                    if (isset($subscribed[$client_id])) {
                                        if ($debug > 2) {
                                            logActivity("ezchimp_output: unsubscribed1 update3 - " . print_r($subscribed, true));
                                        }
                                        $query = "UPDATE `tblcustomfieldsvalues` SET `value`='' where `relid`=$client_id AND `fieldid`=$field_id";
                                        mysql_query($query);
                                    }
                                }
                            }
                            if (!(is_array($list_unsub))) {
                                $query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306 AND `fieldoptions`='" . mysql_real_escape_string($list_id2) . "'";
                                $result = mysql_query($query);
                                $row = mysql_fetch_assoc($result);
                                $field_id = $row['id'];
                                if ($debug > 0) {
                                    logActivity("ezchimp_hook_order: unsubscription1 field4 - " . print_r($row['id'], true));
                                }
                                mysql_free_result($result);
                                $query = "SELECT DISTINCT `relid` FROM `tblcustomfieldsvalues` WHERE `fieldid` = $field_id AND `value`='on'";
                                $result = mysql_query($query);
                                $subscribed = array();
                                while ($row = mysql_fetch_assoc($result)) {
                                    $subscribed[$row['relid']] = 1;
                                }
                                mysql_free_result($result);
                                if (isset($subscribed[$client_id])) {
                                    if ($debug > 2) {
                                        logActivity("ezchimp_output: unsubscribed1 update4 - " . print_r($subscribed, true));
                                    }
                                    $query = "UPDATE `tblcustomfieldsvalues` SET `value`='' where `relid`=$client_id AND `fieldid`=$field_id";
                                    mysql_query($query);
                                }
                            }
                        }
                    }
                    foreach($groupings1[$productgroup_name] as $list_id2 =>$list_unsub){
                        $subscription_groupings = array();
                        if (!empty($list_unsub)) {
                            if ($debug > 0) {
                                logActivity("ezchimp_hook_order: unsubscription2 group1 -$list_unsub ");
                            }
                            foreach ($list_unsub as $maingroups => $groups) {
                                $val='';
                                foreach ($sub as $subgrp => $subgrps1) {
                                    foreach ($sub as $subgrp => $subgrps) {
                                        if (!($subgrps['unsubscribe'] == 1)) {
                                            foreach ($subgrps1['grouping'] as $maingroup1) {
                                                if (!(strcmp($maingroups, $maingroup1['name']))) {
                                                    if ($debug > 0) {
                                                        logActivity("ezchimp_hook_order: unsubscription main1ch1 - " . print_r($maingroups, true));
                                                    }
                                                    foreach ($subgrps['grouping'] as $maingroup) {
                                                        if (!(strcmp($maingroup1['name'],$maingroup['name']))) {
                                                            $val=true;
                                                            if ($debug > 0) {
                                                                logActivity("ezchimp_hook_order: unsubscription main1ch2 - " . print_r($val, true));
                                                            }
                                                        } else{
                                                            if ($debug > 0) {
                                                                logActivity("ezchimp_hook_order: unsubscription main1ch3 - " . print_r($val, true));
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                                if ($val == true) {
                                    foreach ($sub as $subgrp => $subgrps) {
                                        if (!($subgrps['unsubscribe'] == 1)) {
                                            foreach ($subgrps['grouping'] as $maingroup) {
                                                $subscription_groupings = array();
                                                $groups_str = '';
                                                if ($debug > 0) {
                                                    logActivity("ezchimp_hook_order: unsub2 - " . print_r($maingroup['groups'], true));
                                                }
                                                if (!(strcmp($maingroups, $maingroup['name']))) {
                                                    if (!empty($maingroup['groups'])) {
                                                        $grps1 = explode(",", $maingroup['groups']);
                                                        $grps2 = array_unique($grps1);
                                                        $grps3 = implode(',', $grps2);
                                                        if ($debug > 0) {
                                                            logActivity("ezchimp_hook_order: scheck1 - " . print_r($grps3, true));
                                                        }
                                                        $groups_str .= $grps3 . ',';
                                                    }
                                                    if ('' != $groups_str) {
                                                        $groups_str = substr($groups_str, 0, -1);
                                                        $subscription_groupings[] = array('name' => $maingroups, 'groups' => $groups_str);
                                                        if ($debug > 0) {
                                                            logActivity("ezchimp_hook_order: unsubscription case1 - " . print_r($maingroups, true));
                                                        }
                                                        if ($debug > 0) {
                                                            logActivity("ezchimp_hook_order: unsubscription grp1 - " . print_r($groups_str, true));
                                                        }
                                                        $subscriptions1[] = array('list' => $subgrps['list'], 'grouping' => $subscription_groupings);
                                                    }
                                                }
                                            }
                                        }
                                        if (($subgrps['unsubscribe'] == 1)) {
                                            $subscription_groupings1 = array();
                                            $subscriptions2[] = array('list' => $subgrps['list'], 'grouping' => $subscription_groupings1);
                                            if ($debug > 0) {
                                                logActivity("_ezchimp_get_client_unsubscriptions: single prodname1 -". print_r($subscriptions2, true));

                                            }
                                            if ($debug > 0) {
                                                logActivity("_ezchimp_get_client_unsubscriptions: listid - ". print_r($subgrps['list'], true));
                                            }
                                        }
                                    }
                                } else {
                                    $groups_str = '';
                                    $subscription_groupings[] = array('name' => $maingroups, 'groups' => $groups_str);
                                    if ($debug > 0) {
                                        logActivity("ezchimp_hook_order: unsubscription main1 - " . print_r($maingroups, true));
                                    }
                                    if ($debug > 0) {
                                        logActivity("ezchimp_hook_order: unsubscription maingrp1 - " . print_r($groups_str, true));
                                    }
                                    $subscriptions1[] = array('list' => $list_id2, 'grouping' => $subscription_groupings);
                                }
                            }
                            if (!(is_array($list_unsub))) {
                                $subscription_groupings1 = array();
                                $subscriptions2[] = array('list' => $list_unsub, 'grouping' => $subscription_groupings1);
                                if ($debug > 0) {
                                    logActivity("_ezchimp_get_client_subscriptions: single prodname2 -". print_r($subscriptions2, true));

                                }
                                if ($debug > 0) {
                                    logActivity("_ezchimp_get_client_subscriptions: listid - ". print_r($list_unsub, true));
                                }
                            }
                        }
                        foreach ($subscriptions2 as $subscription) {
                            _ezchimp_mailchimp_unsubscribe($subscription, $email);
                        }
                        foreach ($subscriptions1 as $subscription) {
                            _ezchimp_mailchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type);
                        }
                    }
                } else {
                    $errors[] = "Could not get client details: " . $client_id;
                }
            } else {
                $errors[] = "Product group [" . $productgroup_name . "] lists not found in map";
            }
        }
    }
    if (!empty($errors)) {
        logActivity("ezchimp_hook_order: Errors - " . implode('. ', $errors));
    }
}

function _ezchimp_init_client_subscriptions($client_id) {
    global $debug, $config, $settings;

    /* Subscribe to all active lists */
    $result = select_query('tblcustomfields', 'id, fieldoptions', array('type' => 'client', 'fieldtype' => 'tickbox', 'sortorder' => 46306));
    while ($row = mysql_fetch_assoc($result)) {
        $field_id = $row['id'];
        $query = "INSERT INTO `tblcustomfieldsvalues` (`fieldid`, `relid`, `value`) VALUES ('$field_id', '$client_id', 'on')";
        mysql_query($query);
        if ($debug > 2) {
            logActivity("_ezchimp_init_client_subscriptions: subscribed - $client_id, ".$row['fieldoptions']);
        }
    }
    mysql_free_result($result);

    $activelists = unserialize($settings['activelists']);
    if ($debug > 2) {
        logActivity("_ezchimp_init_client_subscriptions: activelists - ".print_r($activelists, true));
    }

    $subscriptions = array();
    foreach ($activelists as $list => $groups) {
        if (!is_array($groups)) {
            $subscriptions[] = array('list' => $list);
        } else {
            $subscription_groupings = array();
            foreach ($groups as $maingroup => $groups) {
                $groups_str = '';
                foreach ($groups as $group) {
                    $groups_str .= str_replace(',', '\\,', $group).',';
                }
                if ('' != $groups_str) {
                    $groups_str = substr($groups_str, 0, -1);
                    $subscription_groupings[] = array('name' => $maingroup, 'groups' => $groups_str);
                }
            }
            if (!empty($subscription_groupings)) {
                $subscriptions[] = array('list' => $list, 'grouping' => $subscription_groupings);
            }
        }
    }
    if ($debug > 1) {
        logActivity("_ezchimp_init_client_subscriptions: subscriptions - ".print_r($subscriptions, true));
    }

    return $subscriptions;
}

function _ezchimp_get_client_subscriptions($client_id) {
    global $debug, $config, $settings;

    $fields = array();
    $result = select_query('tblcustomfields', 'id, fieldoptions', array('type' => 'client', 'fieldtype' => 'tickbox', 'sortorder' => 46306));
    while ($row = mysql_fetch_assoc($result)) {
        $fields[$row['id']] = $row['fieldoptions'];
    }
    mysql_free_result($result);
    if ($debug > 2) {
        logActivity("_ezchimp_get_client_subscriptions: fields - ".print_r($fields, true));
    }

    $list_groups = array();
    $result = select_query('tblcustomfieldsvalues', 'fieldid, value', array('relid' => $client_id));
    while ($row = mysql_fetch_assoc($result)) {
        if (isset($fields[$row['fieldid']])) {
            $list = $fields[$row['fieldid']];
            $status = $row['value'];
            if ('on' == $status) {
                if (strpos($list, '^:') === false) {
                    $list_groups[$list] = array();
                } else {
                    $parts = explode('^:', $list);
                    if (!empty($parts[2])) {
                        $list_id = $parts[0];
                        $maingroup = $parts[1];
                        $group = $parts[2];
                        $list_groups[$list_id][$maingroup][] = $group;
                    }
                }
            }
        }
    }
    mysql_free_result($result);
    if ($debug > 2) {
        logActivity("_ezchimp_get_client_subscriptions: list_groups - ".print_r($list_groups, true));
    }

    $subscriptions = array();
    $all_lists = array();
    $params = array('apikey' => $config['apikey']);
    $lists_result = _ezchimp_call_mailchimp_api('lists', $params);
    if (!empty($lists_result->data)) {
        foreach ($lists_result->data as $list) {
            $all_lists[$list->id] = 1;
        }
    }
    if ($debug > 2) {
        logActivity("_ezchimp_get_client_subscriptions: all_lists - ".print_r($all_lists, true));
    }

    foreach ($list_groups as $list => $groups) {
        unset($all_lists[$list]);
        if (empty($groups)) {
            $subscriptions[] = array('list' => $list);
        } else {
            $subscription_groupings = array();
            $all_groups = array();
            $params['id'] = $list;
            $groupings = _ezchimp_call_mailchimp_api('listInterestGroupings', $params);
            if (!empty($groupings)) {
                foreach ($groupings as $grouping) {
                    $all_groups[$grouping->name] = 1;
                }
            }
            if ($debug > 2) {
                logActivity("_ezchimp_get_client_subscriptions: all_groups - ".print_r($all_groups, true));
            }
            foreach ($groups as $maingroup => $groups) {
                unset($all_groups[$maingroup]);
                $groups_str = '';
                foreach ($groups as $group) {
                    $groups_str .= str_replace(',', '\\,', $group).',';
                }
                if ('' != $groups_str) {
                    $groups_str = substr($groups_str, 0, -1);
                    $subscription_groupings[] = array('name' => $maingroup, 'groups' => $groups_str);
                }
            }
            if (!empty($all_groups)) {
                foreach ($all_groups as $maingroup => $one) {
                    $subscription_groupings[] = array('name' => $maingroup, 'groups' => '');
                    if ($debug > 3) {
                        logActivity("_ezchimp_get_client_subscriptions: empty main group - $maingroup");
                    }
                }
            }
            if (!empty($subscription_groupings)) {
                $subscriptions[] = array('list' => $list, 'grouping' => $subscription_groupings);
            }
        }
    }
    if ($debug > 3) {
        logActivity("_ezchimp_get_client_subscriptions: remaining all_lists - ".print_r($all_lists, true));
    }
    foreach ($all_lists as $list => $one) {
        $subscriptions[] = array('list' => $list, 'unsubscribe' => 1);
    }
    if ($debug > 1) {
        logActivity("_ezchimp_get_client_subscriptions: subscriptions - ".print_r($subscriptions, true));
    }

    return $subscriptions;
}

function _ezchimp_get_listgroup_subscriptions($client_id) {
    global $debug, $config, $settings;

    $fields = array();
    $result = select_query('tblcustomfields', 'id, fieldoptions', array('type' => 'client', 'fieldtype' => 'tickbox', 'sortorder' => 46306));
    while ($row = mysql_fetch_assoc($result)) {
        $fields[$row['id']] = $row['fieldoptions'];
    }
    mysql_free_result($result);
    if ($debug > 2) {
        logActivity("_ezchimp_get_client_subscriptions: fields - ".print_r($fields, true));
    }

    $list_groups = array();
    $result = select_query('tblcustomfieldsvalues', 'fieldid, value', array('relid' => $client_id));

    while ($row = mysql_fetch_assoc($result)) {
        if (isset($fields[$row['fieldid']])) {
            $list = $fields[$row['fieldid']];
            $status = $row['value'];
            if ('on' == $status) {
                if (strpos($list, '^:') === false) {
                    $list_groups[$list] = array();
                } else {
                    $parts = explode('^:', $list);
                    if (!empty($parts[2])) {
                        $list_id = $parts[0];
                        $maingroup = $parts[1];
                        $group = $parts[2];
                        $list_groups[$list_id][$maingroup][] = $group;
                    }
                }
            }
        }
    }
    mysql_free_result($result);
    if ($debug > 2) {
        logActivity("_ezchimp_get_client_subscriptions: list_groups - ".print_r($list_groups, true));
    }

    $subscriptions = array();
    $all_lists = array();
    $params = array('apikey' => $config['apikey']);
    $lists_result = _ezchimp_call_mailchimp_api('lists', $params);
    if (!empty($lists_result->data)) {
        foreach ($lists_result->data as $list) {
            $all_lists[$list->id] = 1;
        }
    }
    if ($debug > 2) {
        logActivity("_ezchimp_get_client_subscriptions: all_lists - ".print_r($all_lists, true));
    }

    foreach ($list_groups as $list => $groups) {
        unset($all_lists[$list]);
        if (empty($groups)) {
            $subscriptions[] = array('list' => $list);
        } else {
            $subscription_groupings = array();
            $all_groups = array();
            $params['id'] = $list;
            $groupings = _ezchimp_call_mailchimp_api('listInterestGroupings', $params);
            if (!empty($groupings)) {
                foreach ($groupings as $grouping) {
                    $all_groups[$grouping->name] = 1;
                }
            }
            if ($debug > 2) {
                logActivity("_ezchimp_get_client_subscriptions: all_groups - ".print_r($all_groups, true));
            }
            if ($debug > 2) {
                logActivity("_ezchimp_get_client_subscriptions: interest_groups - ".print_r($groupings, true));
            }
            foreach ($groups as $maingroup => $groups) {
                unset($all_groups[$maingroup]);
                $groups_str = '';
                foreach ($groups as $group) {
                    $groups_str .= str_replace(',', '\\,', $group).',';
                }
                if ('' != $groups_str) {
                    $groups_str = substr($groups_str, 0, -1);
                    $subscription_groupings[] = array('name' => $maingroup, 'groups' => $groups_str);
                }
            }
            foreach ($all_groups as $gr) {
                if ($debug > 2) {
                    logActivity("ezchimp_get_client_subscriptions: all_groups2 - ".print_r($gr, true));
                }
            }
//            if (!empty($all_groups)) {
//                foreach ($all_groups as $maingroup => $one) {
//                    $subscription_groupings[] = array('name' => $maingroup, 'groups' => '');
//                    if ($debug > 3) {
//                        logActivity("_ezchimp_get_client_subscriptions: empty main group - $maingroup");
//                    }
//                }
//            }
            if (!empty($subscription_groupings)) {
                $subscriptions[] = array('list' => $list, 'grouping' => $subscription_groupings);
            }
        }
    }
    if ($debug > 3) {
        logActivity("_ezchimp_get_client_subscriptions: remaining all_lists - ".print_r($all_lists, true));
    }
    foreach ($all_lists as $list => $one) {
        $subscriptions[] = array('list' => $list, 'unsubscribe' => 1);
    }
    if ($debug > 1) {
        logActivity("_ezchimp_get_client_subscriptions: subscriptions - ".print_r($subscriptions, true));
    }

    return $subscriptions;
}

function _ezchimp_get_client_email_type($client_id) {
    global $debug, $config, $settings;

    $email_type = $settings['default_format'];

    $field_id = 0;
    $result = select_query('tblcustomfields', 'id', array('type' => 'client', 'fieldtype' => 'dropdown', 'fieldname' => 'Email format', 'sortorder' => 46307));
    if ($row = mysql_fetch_assoc($result)) {
        $field_id = $row['id'];
    }
    mysql_free_result($result);
    if ($debug > 1) {
        logActivity("_ezchimp_get_client_email_type: field_id - $field_id");
    }

    if ($field_id > 0) {
        $result = select_query('tblcustomfieldsvalues', 'value', array('fieldid' => $field_id, 'relid' => $client_id));
        if ($row = mysql_fetch_assoc($result)) {
            $email_type = strtolower($row['value']);
        }
        mysql_free_result($result);
        if ($debug > 2) {
            logActivity("_ezchimp_get_client_email_type: email_type - $email_type");
        }
    }

    return $email_type;
}

function _ezchimp_get_client_subscribe_contacts($client_id) {
    global $debug, $config, $settings;

    $subscribecontacts = '';

    $field_id = 0;
    $result = select_query('tblcustomfields', 'id', array('type' => 'client', 'fieldtype' => 'tickbox', 'fieldname' => 'Subscribe all contacts', 'sortorder' => 46309));
    if ($row = mysql_fetch_assoc($result)) {
        $field_id = $row['id'];
    }
    mysql_free_result($result);
    if ($debug > 1) {
        logActivity("_ezchimp_get_client_subscribe_contacts: field_id - $field_id");
    }

    if ($field_id >0) {
        $result = select_query('tblcustomfieldsvalues', 'value', array('fieldid' => $field_id, 'relid' => $client_id));
        if ($row = mysql_fetch_assoc($result)) {
            $subscribecontacts = $row['value'];
        }
        mysql_free_result($result);
        if ($debug > 2) {
            logActivity("_ezchimp_get_client_subscribe_contacts: subscribecontacts - $subscribecontacts");
        }
    }

    return $subscribecontacts;
}

function _ezchimp_mailchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type='html') {
    global $debug, $config, $settings;

    $merge_vars = array(
        'MERGE0' => $email,
        'MERGE1' => $firstname,
        'MERGE2' => $lastname
    );
    if (!empty($subscription['grouping'])) {
        $merge_vars['GROUPINGS'] = $subscription['grouping'];
    }

    $params = array(
        'id' => $subscription['list'],
        'apikey' => $config['apikey'],
        'email_address' => $email,
        'email_type' => $email_type,
        'double_optin' => (isset($settings['double_optin']) && ('on' == $settings['double_optin'])) ? true : false,
        'merge_vars' => $merge_vars,
        'update_existing' => true,
        'replace_interests' => true,
    );
    _ezchimp_call_mailchimp_api('listSubscribe', $params);
}


function _ezchimp_mailchimp_unsubscribe($subscription, $email) {
    global $debug, $config, $settings;

    $params = array(
        'id' => $subscription['list'],
        'email_address' => $email,
        'apikey' => $config['apikey'],
        'delete_member' => (isset($settings['delete_member']) && ('on' == $settings['delete_member'])) ? true : false,
        'send_goodbye' => (isset($settings['send_goodbye']) && ('on' == $settings['send_goodbye'])) ? true : false,
        'send_notify' => (isset($settings['send_notify']) && ('on' == $settings['send_notify'])) ? true : false,
    );
    _ezchimp_call_mailchimp_api('listUnsubscribe', $params);
}

function ezchimp_hook_client_add($vars) {
    global $debug, $config, $settings;

    _ezchimp_hook_init();

    if ($debug > 0) {
        logActivity("ezchimp_hook_client_add: vars - ".print_r($vars, true));
    }

    $client_id = $vars['userid'];
    $firstname = $vars['firstname'];
    $lastname = $vars['lastname'];
    $email = $vars['email'];

    if ('on' == $settings['showorder']) {
        $subscriptions = _ezchimp_get_client_subscriptions($client_id);
    } else if ('on' == $settings['default_subscribe']) {
        $subscriptions = _ezchimp_init_client_subscriptions($client_id);
    }

    $email_type = _ezchimp_get_client_email_type($client_id);
    foreach ($subscriptions as $subscription) {
        if (isset($subscription['unsubscribe'])) {
            _ezchimp_mailchimp_unsubscribe($subscription, $email);
            if ($debug > 1) {
                logActivity("ezchimp_hook_client_add: unsubscribe - $email, ".$subscription['list']);
            }
        } else {
            _ezchimp_mailchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type);
            if ($debug > 1) {
                logActivity("ezchimp_hook_client_add: subscribe - $email, ".print_r($subscription, true));
            }
        }
    }
}

function ezchimp_hook_client_edit($vars) {
    global $debug, $config, $settings;

    _ezchimp_hook_init();

    if ($debug > 0) {
        logActivity("ezchimp_hook_client_edit: vars - ".print_r($vars, true));
    }

    $client_id = $vars['userid'];
    $email = $vars['email'];
    if (isset($vars['status'])) {
        $status = $vars['status'];
    } else if (isset($vars['olddata']['status'])) {
        $status = $vars['olddata']['status'];
    } else {
        $status = 'Active';
    }

    if (!empty($vars['firstname'])) {
        $firstname = $vars['firstname'];
        $lastname = $vars['lastname'];
    } else {
        $result = select_query('tblclients', 'firstname, lastname', array('id' => $client_id));
        if ($client = mysql_fetch_assoc($result)) {
            $firstname = $client['firstname'];
            $lastname = $client['lastname'];
        } else {
            $firstname = '';
            $lastname = '';
        }
    }

    $subscriptions = _ezchimp_get_client_subscriptions($client_id);

    $email_type = _ezchimp_get_client_email_type($client_id);
    foreach ($subscriptions as $subscription) {
        if (isset($subscription['unsubscribe']) || ('Active' != $status)) {
            _ezchimp_mailchimp_unsubscribe($subscription, $email);
            if ($debug > 1) {
                logActivity("ezchimp_hook_client_edit: unsubscribe - $email, ".$subscription['list']);
            }
        } else {
            _ezchimp_mailchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type);
            if ($debug > 1) {
                logActivity("ezchimp_hook_client_edit: subscribe - $email, ".print_r($subscription, true));
            }
        }
    }

    $result = select_query('tblcontacts', 'firstname, lastname, email', array('userid' => $client_id));
    if (('on' == $settings['subscribe_contacts']) && ('on' == _ezchimp_get_client_subscribe_contacts($client_id)) && ('Active' == $status)) {
        while ($contact = mysql_fetch_assoc($result)) {
            foreach ($subscriptions as $subscription) {
                if ($contact['email'] != $email) {
                    if (isset($subscription['unsubscribe'])) {
                        _ezchimp_mailchimp_unsubscribe($subscription, $contact['email']);
                        if ($debug > 1) {
                            logActivity("ezchimp_hook_client_edit: unsubscribe contact - ".$contact['email'].", ".$subscription['list']);
                        }
                    } else {
                        _ezchimp_mailchimp_subscribe($subscription, $contact['firstname'], $contact['lastname'], $contact['email'], $email_type);
                        if ($debug > 1) {
                            logActivity("ezchimp_hook_client_edit: subscribe contact - ".$contact['email'].", ".print_r($subscription, true));
                        }
                    }
                }
            }
        }
    } else {
        while ($contact = mysql_fetch_assoc($result)) {
            if ($contact['email'] != $email) {
                foreach ($subscriptions as $subscription) {
                    _ezchimp_mailchimp_unsubscribe($subscription, $contact['email']);
                    if ($debug > 1) {
                        logActivity("ezchimp_hook_client_edit: unsubscribe contact - ".$contact['email'].", ".$subscription['list']);
                    }
                }
            }
        }
    }
}

/**
 * Function for WHMCS PreDeleteClient hook
 * Note: This code is not to be used now.
 * It is left here as a starting point when
 * implementing unsubscription on client delete.
 *
 * @param array
 */
function ezchimp_hook_client_delete($vars) {
    global $debug, $config, $settings;

    _ezchimp_hook_init();

    if ($debug > 0) {
        logActivity("ezchimp_hook_client_delete: vars - ".print_r($vars, true));
    }
}

function ezchimp_hook_contact_subscribe($vars) {
    global $debug, $config, $settings;

    _ezchimp_hook_init();

    if ($debug > 0) {
        logActivity("ezchimp_hook_contact_subscribe: vars - ".print_r($vars, true));
    }

    $client_id = $vars['userid'];
    $firstname = $vars['firstname'];
    $lastname = $vars['lastname'];
    $email = $vars['email'];

    if (('on' == $settings['subscribe_contacts']) && ('on' == _ezchimp_get_client_subscribe_contacts($client_id))) {
        $subscriptions = _ezchimp_get_client_subscriptions($client_id);

        $email_type = _ezchimp_get_client_email_type($client_id);
        foreach ($subscriptions as $subscription) {
            _ezchimp_mailchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type);
            if ($debug > 1) {
                logActivity("ezchimp_hook_contact_subscribe: subscribe contact - $email, ".print_r($subscription, true));
            }
        }
    }
}


/*
 * Register the hooks
 */
add_hook("ClientAdd",1,"ezchimp_hook_client_add");
add_hook("ClientEdit",1,"ezchimp_hook_client_edit");
//add_hook("PreDeleteClient",1,"ezchimp_hook_client_delete");
add_hook("ContactAdd",1,"ezchimp_hook_contact_subscribe");
add_hook("ContactEdit",1,"ezchimp_hook_contact_subscribe");
add_hook("AcceptOrder",1,"ezchimp_hook_order");

?>
