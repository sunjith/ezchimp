<?php
/*
**********************************************

* ezchimp - MailChimp Newsletter integration *

Author: Sunjith P S
License: GPLv3

Copyright AdMod Technologies Pvt Ltd
http://www.admod.com

**********************************************
*/

if (!defined("WHMCS"))
	die("This file cannot be accessed directly");

/*
**********************************************

         *** MailChimp Newsletter module ***

This ezchimp addon module is for
MailChimp newsletter integration.

Please refer to the PDF documentation @
http://wiki.whmcs.com/Addon_Modules
for more information regarding WHMCS module
development.

**********************************************
*/

define('EMAILS_LIMIT', 25);

class EzchimpConf {
    public $debug = 0;
}
class EzchimpAllVars {
    public $debug = 0;
    public $config = array();
    public $settings = array();
}

function ezchimp_config() {
    $configarray = array(
    "name" => "MailChimp newsletter",
    "description" => "Integrates with MailChimp. Supports subscribe/unsubscribe, multiple mailing lists and interest groups, multi-language.", //, synchronization
    "version" => "1.18",
    "author" => "AdMod Technologies - www.admod.com",
    "language" => "english",
    "fields" => array(
        "apikey" => array ("FriendlyName" => "MailChimp API Key", "Type" => "text", "Size" => "100", "Description" => "Enter your MailChimp API key." ),
        "baseurl" => array ("FriendlyName" => "WHMCS Base URL", "Type" => "text", "Size" => "100", "Description" => "Enter the base URL of your WHMCS. Eg: http://yourcompany.com/whmcs" ),
        //"unsubscribe" => array ("FriendlyName" => "Unsubscribe all on deactivation", "Type" => "yesno", "Description" => "Unsubscribe all subscribed emails in MailChimp mailing list on deactivation of this module." ),
        "delete" => array ("FriendlyName" => "Delete newsletter fields on deactivation", "Type" => "yesno", "Description" => "Delete table and newsletter custom fields added by this module on deactivation. If enabled, settings and newsletter subscription statuses will be lost when you activate this module again." ),
        "debug" => array ("FriendlyName" => "Debug level", "Type" => "dropdown", "Options" => "0,1,2,3,4,5", "Description" => "Lot of debugging messages will be logged in Activity Logs", "Default" => "0" ),
    ));
    return $configarray;
}

function module_language_init() {
    /* Get WHMCS default language */
    $result = select_query('tblconfiguration', 'value', array('setting' => 'Language'));
    $row = mysql_fetch_assoc($result);
    $default_lang = $row['value'];
    mysql_free_result($result);
    $_ADDONLANG = array();
    $lang_file = dirname(__FILE__) . '/lang/' . strtolower($default_lang) . '.php';
    if (file_exists($lang_file)) {
        include($lang_file);
    } else {
        logActivity("module_language_init: $lang_file ($default_lang) not found!");
        $lang_file = dirname(__FILE__) . '/lang/' . $default_lang . '.php';
        if (file_exists($lang_file)) {
            include($lang_file);
        } else {
            logActivity("module_language_init: $lang_file ($default_lang) not found! Fall back to English.");
            include(dirname(__FILE__) . '/lang/english.php');
        }
    }
    return $_ADDONLANG;
}

function ezchimp_activate() {
    $ezconf = new EzchimpConf();
    $LANG = module_language_init();

    # Create table and custom client fields for newsletter
	$query = "CREATE TABLE `mod_ezchimp` (`setting` VARCHAR(30) NOT NULL, `value` TEXT NOT NULL DEFAULT '', PRIMARY KEY (`setting`) )";
	if (!($result = mysql_query($query))) {
        return array('status'=>'error','description'=>'Could not create table: '.mysql_error());
    }
	/* Default settings */
	$settings = array(
				'format_select' => 'on',
				'interest_select' => 'on',
				'subscribe_contacts' => 'on',
				'showorder' => 'on',
				'double_optin' => 'on',
				'default_format' => 'html'
				);
	if ($ezconf->debug > 1) {
		logActivity("ezchimp_activate: module settings init - ".print_r($settings, true));
	}
	foreach ($settings as $setting => $value) {
		$query = "INSERT INTO `mod_ezchimp` (`setting`, `value`) VALUES ('$setting', '$value')";
		if (!($result = mysql_query($query))) {
            mysql_query("DROP TABLE `mod_ezchimp`");
            return array('status'=>'error','description'=>'Could not add default settings');
        }
	}

    $query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='dropdown' AND `sortorder`=46307"; // `fieldname`='Email format'
	$result = mysql_query($query);
	if (mysql_num_rows($result) > 0) {
		$query = "UPDATE `tblcustomfields` SET `adminonly`='', `showorder`='on' WHERE `type`='client' AND `fieldname`='".mysql_real_escape_string($LANG['email_format'])."' AND `fieldtype`='dropdown' AND `sortorder`=46307";
	} else {
		$query = "INSERT INTO `tblcustomfields` (`type`, `relid`, `fieldname`, `fieldtype`, `description`, `fieldoptions`, `regexpr`, `adminonly`, `required`, `showorder`, `showinvoice`, `sortorder`) VALUES ('client', 0, '".mysql_real_escape_string($LANG['email_format'])."', 'dropdown', '".mysql_real_escape_string($LANG['email_format_desc'])."', 'HTML,Mobile,Text', '', '', '', 'on', '', 46307)";
	}
	mysql_free_result($result);
	if (!($result = mysql_query($query))) {
        mysql_query("DROP TABLE `mod_ezchimp`");
        return array('status'=>'error','description'=>'Could not add custom field');
    }

    $query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46309"; // `fieldname`='Subscribe all contacts'
	$result = mysql_query($query);
	if (mysql_num_rows($result) > 0) {
		$query = "UPDATE `tblcustomfields` SET `adminonly`='', `showorder`='on' WHERE `type`='client' AND `fieldname`='".mysql_real_escape_string($LANG['subscribe_all_contacts'])."' AND `fieldtype`='tickbox' AND `sortorder`=46309";
	} else {
		$query = "INSERT INTO `tblcustomfields` (`type`, `relid`, `fieldname`, `fieldtype`, `description`, `fieldoptions`, `regexpr`, `adminonly`, `required`, `showorder`, `showinvoice`, `sortorder`) VALUES ('client', 0, '".mysql_real_escape_string($LANG['subscribe_all_contacts'])."', 'tickbox', '".mysql_real_escape_string($LANG['subscribe_all_contacts_desc'])."', '', '', '', '', 'on', '', 46309)";
	}
	mysql_free_result($result);
    if (!($result = mysql_query($query))) {
        mysql_query("DROP TABLE `mod_ezchimp`");
        return array('status'=>'error','description'=>'Could not add custom field');
    }
    return array('status'=>'success','description'=>'ezchimp - Mailchimp Newsletter addon module activated');
}

function ezchimp_deactivate() {
    $ezconf = new EzchimpConf();
    $LANG = module_language_init();

	/* Module vars */
	$config = _ezchimp_config($ezconf);
	if ($ezconf->debug > 1) {
		logActivity("ezchimp_deactivate: module config - ".print_r($config, true));
	}

	if (isset($config['delete']) && ('on' == $config['delete'])) {
		# Remove table and custom client fields
		$query = "DROP TABLE `mod_ezchimp`";
		mysql_query($query);

		$query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='dropdown' AND `sortorder`=46307"; // `fieldname`='Email format'
		$result = mysql_query($query);
		$emailformat_fieldid = 0;
		if ($row = mysql_fetch_assoc($result)) {
			$emailformat_fieldid = $row['id'];
		}
		mysql_free_result($result);
		if ($ezconf->debug > 1) {
			logActivity("ezchimp_deactivate: emailformat_fieldid - $emailformat_fieldid");
		}
		if ($emailformat_fieldid > 0) {
			$query = "DELETE FROM `tblcustomfieldsvalues` WHERE `fieldid`=$emailformat_fieldid";
			mysql_query($query);
		}
		$query = "DELETE FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='dropdown' AND `sortorder`=46307"; // `fieldname`='Email format'
		mysql_query($query);

		$query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46309"; // `fieldname`='Subscribe all contacts'
		$result = mysql_query($query);
		$subscribe_contacts_fieldid = 0;
		if ($row = mysql_fetch_assoc($result)) {
			$subscribe_contacts_fieldid = $row['id'];
		}
		mysql_free_result($result);
		if ($ezconf->debug > 1) {
			logActivity("ezchimp_deactivate: subscribe_contacts_fieldid - $subscribe_contacts_fieldid");
		}
		if ($subscribe_contacts_fieldid > 0) {
			$query = "DELETE FROM `tblcustomfieldsvalues` WHERE `fieldid`=$subscribe_contacts_fieldid";
			mysql_query($query);
		}
		$query = "DELETE FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46309"; // `fieldname`='Subscribe all contacts'
		mysql_query($query);

		$query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306";
		$result = mysql_query($query);
		$fieldids = array();
		while ($row = mysql_fetch_assoc($result)) {
			$fieldids[] = $row['id'];
		}
		mysql_free_result($result);
		if ($ezconf->debug > 1) {
			logActivity("ezchimp_deactivate: fieldids - ".print_r($fieldids, true));
		}
		if (!empty($fieldids)) {
			$fieldsids_str = implode(',', $fieldids);
			$query = "DELETE FROM `tblcustomfieldsvalues` WHERE `fieldid` IN ($fieldsids_str)";
			mysql_query($query);
		}
		$query = "DELETE FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306";
		mysql_query($query);
	} else {
		$query = "UPDATE `tblcustomfields` SET `adminonly`='on', `showorder`='' WHERE `type`='client' AND `fieldname`='".mysql_real_escape_string($LANG['subscribe_all_contacts'])."' AND `fieldtype`='tickbox' AND `sortorder`=46309";
		mysql_query($query);
		$query = "UPDATE `tblcustomfields` SET `adminonly`='on', `showorder`='' WHERE `type`='client' AND `fieldname`='".mysql_real_escape_string($LANG['email_format'])."' AND `fieldtype`='dropdown' AND `sortorder`=46307";
		mysql_query($query);
		$query = "UPDATE `tblcustomfields` SET `adminonly`='on', `showorder`='' WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306";
		mysql_query($query);
	}

    # Return Result
    return array('status'=>'success','description'=>'Thank you for using ezchimp. - www.admod.com');

}

function ezchimp_upgrade($vars) {
    $version = $vars['version'];
}

function ezchimp_output($vars) {
    $ezconf = new EzchimpConf();

    $modulelink = $vars['modulelink'];
    $version = $vars['version'];
    $apikey = $vars['apikey'];
    $LANG = $vars['_lang'];

    if (isset($vars['debug'])) {
    	$ezconf->debug = intval($vars['debug']);
    	if ($ezconf->debug > 0) {
    		logActivity("ezchimp_output: Debug enabled - ".$ezconf->debug);
    	}
    }

    /* Get WHMCS version */
    $result = select_query('tblconfiguration', 'value', array('setting' => 'Version'));
    $row = mysql_fetch_assoc($result);
    $whmcs_version = $row['value'];
    mysql_free_result($result);

	$settings = _ezchimp_settings($ezconf);
    if ($ezconf->debug > 0) {
    	logActivity("ezchimp_output: module settings - ".print_r($settings, true));
    }

    $ezvars = new EzchimpAllVars();
    $ezvars->debug = $ezconf->debug;
    $ezvars->config = $vars;
    $ezvars->settings = $settings;

    /* Until WHMCS 5, the addon sidebar was not supported. So we give it manually in such situation. */
//    if (intval($whmcs_version[0]) < 5) { /* _sidebar not working even in v5 in some cases */
        echo '<div style="float:right;margin:0 20px 0 0;width:200px;border:1px solid #ccc;background-color:#efefef;padding:10px;"><ul class="menu">
            <li><a href="'.$modulelink.'">'.$LANG['settings'].'</a></li>
            <li><a href="'.$modulelink.'&page=lists">'.$LANG['lists_groups'].'</a></li>
            <li><a href="'.$modulelink.'&page=status">'.$LANG['status'].'</a></li>
            <li><a href="'.$modulelink.'&page=tools">'.$LANG['tools'].'</a></li>
            <li><a href="'.$modulelink.'&page=autosubscribe">'.$LANG['auto_subscribe'].'</a></li>
        </ul></div>';
//    }

    echo '<p>ezchimp '.$version.' [WHMCS '.$whmcs_version.']</p>';
    echo '<p>'.$LANG['intro'].'</p>
    <p>'.$LANG['description'].'</p>
    <p>'.$LANG['documentation'].' <a href="http://blog.admod.com/2012/01/23/ezchimp-whmcs-mailchimp-integration/" target="_blank">'.$LANG['here'].'</a></p>
    <p>Copyright &copy; AdMod Technologies - <a href="http://www.admod.com/" target="_blank">www.admod.com</a></p>';

    if (empty($apikey)) {
		echo '<div class="errorbox"><strong>'.$LANG['set_apikey'].' <a href="configaddonmods.php">'.$LANG['module_config'].'</a></strong></div>';
    } else {
	    $page = isset($_GET['page']) ? $_GET['page'] : '';
	    switch ($page) {
	    	case 'status':
                if (isset($_GET['p'])) {
                    $page_number = $_GET['p'];
                } else {
                    $page_number = 1;
                    unset($_SESSION['searchtext'], $_SESSION['seachfield']);
                }

                if (isset($_POST['searchtext'])) {
                    $search_text = $_SESSION['searchtext'] = $_POST['searchtext'];
                } else if (isset($_SESSION['searchtext'])) {
                    $search_text = $_SESSION['searchtext'];
                } else {
                    $search_text = '';
                }
                if (isset($_POST['seachfield'])) {
                    $search_field = $_SESSION['seachfield'] = $_POST['seachfield'];
                } else if (isset($_SESSION['seachfield'])) {
                    $search_field = $_SESSION['seachfield'];
                }
                if (!isset($search_field) || !in_array($search_field, array('email', 'firstname', 'lastname'))) {
                    $search_field = 'email';
                }
                $email_selected = $firstname_selected = $lastname_selected = '';
                ${$search_field.'_selected'} = ' selected="selected"';

                /* Find total no. or rows */
                if ('on' == $settings['subscribe_contacts']) {
                    $query_from = "FROM `tblclients` AS `cl` LEFT JOIN `tblcontacts` AS `ct` ON `cl`.`id` = `ct`.`userid`";
                } else {
                    $query_from = "FROM `tblclients` AS `cl`";
                }
                if ('' != $search_text) {
                    if ('on' == $settings['subscribe_contacts']) {
                        $query_from .= " WHERE `cl`.`$search_field` LIKE '%".mysql_real_escape_string($search_text)."%' OR `ct`.`$search_field` LIKE '%".mysql_real_escape_string($search_text)."%'";
                    } else {
                        $query_from .= " WHERE `cl`.`$search_field` LIKE '%".mysql_real_escape_string($search_text)."%'";
                    }
                }
                $query = "SELECT count(*) AS `total` $query_from";
                if ($ezconf->debug > 4) {
                    logActivity("ezchimp_output: count query - $query");
                }
                $result = mysql_query($query);
                $row = mysql_fetch_assoc($result);
                $total = $row['total'];
                mysql_free_result($result);
                $pages = ceil($total / EMAILS_LIMIT);
	    		if ($ezconf->debug > 3) {
	    			logActivity("ezchimp_output: status - page_number, total, pages : $page_number, $total, $pages");
	    		}

	    		echo '<br /><h2>'.$LANG['status'].'</h2><p>'.$LANG['status_desc'].'</p><p>';
                /* Search form */
                echo '<form action="'.$modulelink.'&page=status" name="SearchForm" method="POST">
                        <input type="text" name="searchtext" value="'.htmlspecialchars($search_text).'" />
                            <select name="seachfield">
                                <option value="email"'.$email_selected.'>Email</option>
                                <option value="firstname"'.$firstname_selected.'>Firstname</option>
                                <option value="lastname"'.$lastname_selected.'>Lastname</option>
                            </select>
                        <input type="submit" name="search" value="Search" />
                    </form>';

                /* Pagination links */
                if ($pages > 1) {
                    if ($page_number > 1) {
                        echo '<a href="'.$modulelink.'&page=status&p='.($page_number - 1).'">'.$LANG['prev'].'</a> ';
                    }
                    if ($page_number < $pages) {
                        echo '<a href="'.$modulelink.'&page=status&p='.($page_number + 1).'">'.$LANG['next'].'</a> ';
                    }
                    echo '&nbsp; ';
                    for ($i = 1; $i <= $pages; $i++) {
                        if ($i == $page_number) {
                            $pg = "<b>$i</b>";
                        } else {
                            $pg = $i;
                        }
                        echo '<a href="'.$modulelink.'&page=status&p='.$i.'">'.$pg.'</a> ';
                    }
                }

	    		echo '</p><div class="tablebg"><table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">
	<tr>
		<th>'.$LANG['client_id'].'</th>';
    			if ('on' == $settings['subscribe_contacts']) {
    				echo '
		<th>'.$LANG['contact_id'].'</th>';
    			}
    			echo '
		<th>'.$LANG['firstname'].'</th>
		<th>'.$LANG['lastname'].'</th>
		<th>'.$LANG['email'].'</th>
		<th>'.$LANG['format'].'</th>
		<th>'.$LANG['list'].'</th>
		<th>'.$LANG['groups'].'</th>
		<th>'.$LANG['status'].'</th>
		<th>'.$LANG['rating'].'</th>
	</tr>';

    			$activelists = unserialize($settings['activelists']);
	    		$listnames = unserialize($settings['listnames']);
    			if ($ezconf->debug > 2) {
    				logActivity("ezchimp_output: activelists - ".print_r($activelists, true));
    				logActivity("ezchimp_output: listnames - ".print_r($listnames, true));
    			}

    			$emails = array();
                $offset = ($page_number - 1) * EMAILS_LIMIT;
                if ('on' == $settings['subscribe_contacts']) {
                    $query = "SELECT `cl`.`id` AS `client_id`, `cl`.`firstname` AS `client_firstname`, `cl`.`lastname` AS `client_lastname`, `cl`.`email` AS `client_email`, `ct`.`id` AS `contact_id`, `ct`.`firstname` AS `contact_firstname`, `ct`.`lastname` AS `contact_lastname`, `ct`.`email` AS `contact_email` $query_from ORDER BY `client_id` ASC, `contact_id` ASC LIMIT $offset, " . EMAILS_LIMIT;
                } else {
                    $query = "SELECT `cl`.`id` AS `client_id`, `cl`.`firstname` AS `client_firstname`, `cl`.`lastname` AS `client_lastname`, `cl`.`email` AS `client_email` $query_from ORDER BY `client_id` ASC LIMIT $offset, " . EMAILS_LIMIT;
                }
	    		if ($ezconf->debug > 4) {
	    			logActivity("ezchimp_output: query - $query");
	    		}
	    		$result = mysql_query($query);
	    		while ($row = mysql_fetch_assoc($result)) {
	    			$clientid = $row['client_id'];
                    if (!isset($emails[$row['client_email']]) && (('' == $search_text) || (stripos($row['client_'.$search_field], $search_text) !== false))) {
                        $emails[$row['client_email']]['clientid'] = $clientid;
                        $emails[$row['client_email']]['firstname'] = $row['client_firstname'];
                        $emails[$row['client_email']]['lastname'] = $row['client_lastname'];
                    }
                    if (!empty($row['contact_id']) && (('' == $search_text) || (stripos($row['contact_'.$search_field], $search_text) !== false))) {
                        $emails[$row['contact_email']]['clientid'] = $clientid;
	    			    $emails[$row['contact_email']]['contactid'] = $row['contact_id'];
                        $emails[$row['contact_email']]['firstname'] = $row['contact_firstname'];
                        $emails[$row['contact_email']]['lastname'] = $row['contact_lastname'];
                    }
	    		}
                mysql_free_result($result);
	    		if ($ezconf->debug > 4) {
	    			logActivity("ezchimp_output: emails - ".print_r($emails, true));
	    		}
	    		$email_statuses = array();
                $email_addresses = array_keys($emails);

                foreach ($activelists as $listid => $groups) {
                    $params = array('apikey' => $apikey, 'id' => $listid, 'email_address' => $email_addresses);
                    $memberinfo = _ezchimp_mailchimp_api('listMemberInfo', $params, $ezconf);
                    if ($ezconf->debug > 4) {
                        logActivity("ezchimp_output: memberinfo - " . print_r($memberinfo, true));
                    }
                    $i = 0;
                    if (isset($memberinfo->data)) {
                        foreach ($memberinfo->data as $entry) {
                            if (isset($entry->email)) {
                                $email = $entry->email;
                                if (!isset($email_statuses[$email])) {
                                    $email_statuses[$email] = $emails[$email];
                                }
                                $email_statuses[$email]['subscriptions'][$listid] = array();
                                $email_statuses[$email]['subscriptions'][$listid]['format'] = isset($entry->email_type) ? $entry->email_type : 'NA';
                                $email_statuses[$email]['subscriptions'][$listid]['status'] = isset($entry->status) ? $entry->status : 'NA';
                                $email_statuses[$email]['subscriptions'][$listid]['rating'] = isset($entry->member_rating) ? $entry->member_rating . ' / 5' : 'NA';
                                $groups_str = '';
                                if (!empty($entry->merges->GROUPINGS)) {
                                    foreach ($entry->merges->GROUPINGS as $grouping) {
                                        if (!empty($grouping->groups)) {
                                            $groups_str .= $grouping->name . ' > ' . $grouping->groups . '<br />';
                                        }
                                    }
                                }
                                $email_statuses[$email]['subscriptions'][$listid]['groups'] = $groups_str;
                            } else if (isset($entry->email_address) && isset($entry->error)) {
                                if ($ezconf->debug > 1) {
                                    logActivity("ezchimp_output: status ($listid) - " . $entry->error);
                                }
                            } else {
                                logActivity("ezchimp_output: status ($listid) - Invalid MemberInfo entry ($i)");
                            }
                            $i++;
                        }
                    } else {
                        logActivity("ezchimp_output: status ($listid) - Invalid MemberInfo");
                    }
                }

                foreach ($email_addresses as $email) {
                    if (!isset($email_statuses[$email])) {
                        $email_statuses[$email] = $emails[$email];
                    }
                }
                if ($ezconf->debug > 4) {
                    logActivity("ezchimp_output: email_statuses - ".print_r($email_statuses, true));
                }

                /* Sort results */
                $clid = $ctid = array();
                foreach ($email_statuses as $email => $info) {
                    $clid[$email] = $info['clientid'];
                    $ctid[$email] = isset($info['contactid']) ? $info['contactid'] : 0;
                }
                array_multisort($clid, SORT_ASC, $ctid, SORT_ASC, $email_statuses);
                if ($ezconf->debug > 4) {
                    logActivity("ezchimp_output: sorted email_statuses - ".print_r($email_statuses, true));
                }

	    		foreach ($email_statuses as $email => $info) {
                    if (empty($info['subscriptions'])) {
                        echo '
	<tr>
		<td><a href="clientssummary.php?userid='.$info['clientid'].'">'.$info['clientid'].'</a></td>';
                        if (isset($info['contactid'])) {
                            echo '
	    <td><a href="clientscontacts.php?userid='.$info['clientid'].'&contactid='.$info['contactid'].'">'.$info['contactid'].'</a></td>
		<td><a href="clientscontacts.php?userid='.$info['clientid'].'&contactid='.$info['contactid'].'">'.$info['firstname'].'</a></td>
		<td><a href="clientscontacts.php?userid='.$info['clientid'].'&contactid='.$info['contactid'].'">'.$info['lastname'].'</a></td>';
                        } else {
                            if ('on' == $settings['subscribe_contacts']) {
                                echo '
        <td>-</td>';
                            }
                            echo '
		<td><a href="clientssummary.php?userid='.$info['clientid'].'">'.$info['firstname'].'</a></td>
		<td><a href="clientssummary.php?userid='.$info['clientid'].'">'.$info['lastname'].'</a></td>';
                        }
                        echo '
		<td><a href="mailto:'.$email.'">'.$email.'</a></td>
		<td>-</td>
		<td>-</td>
		<td>-</td>
		<td>'.$LANG['no_subscription'].'</td>
		<td>-</td>
	</tr>';
                    } else {
                        foreach ($info['subscriptions'] as $listid => $subscription) {
                            echo '
	<tr>
		<td><a href="clientssummary.php?userid='.$info['clientid'].'">'.$info['clientid'].'</a></td>';
                            if (isset($info['contactid'])) {
                                echo '
	    <td><a href="clientscontacts.php?userid='.$info['clientid'].'&contactid='.$info['contactid'].'">'.$info['contactid'].'</a></td>
		<td><a href="clientscontacts.php?userid='.$info['clientid'].'&contactid='.$info['contactid'].'">'.$info['firstname'].'</a></td>
		<td><a href="clientscontacts.php?userid='.$info['clientid'].'&contactid='.$info['contactid'].'">'.$info['lastname'].'</a></td>';
		    			    } else {
                                if ('on' == $settings['subscribe_contacts']) {
                                    echo '
        <td>-</td>';
                            }
		    				    echo '
		<td><a href="clientssummary.php?userid='.$info['clientid'].'">'.$info['firstname'].'</a></td>
		<td><a href="clientssummary.php?userid='.$info['clientid'].'">'.$info['lastname'].'</a></td>';
		    			}
		    			    echo '
		<td><a href="mailto:'.$email.'">'.$email.'</a></td>
		<td>'.$subscription['format'].'</td>
		<td>'.$listnames[$listid].'</td>
		<td>'.$subscription['groups'].'</td>
		<td>'.$subscription['status'].'</td>
		<td>'.$subscription['rating'].'</td>
	</tr>';
	    			    }
                    }
	    		}
	    		echo '
</table></div>';
	    		break;
	    	case 'tools':
	    		echo '<br /><h2>'.$LANG['tools'].'</h2>';
	    		if (!empty($_POST)) {
	    			if (!empty($_POST['action'])) {
		    			$action = $_POST['action'];
		    			if ($ezconf->debug > 1) {
		    				logActivity("ezchimp_output: tools - $action");
		    			}
		    			switch ($action) {
		    				case 'subscribe_empty':
								$fieldids = array();
								$result = select_query('tblcustomfields', 'id', array('type' => 'client', 'fieldtype' => 'tickbox', 'sortorder' => 46306));
								while ($row = mysql_fetch_assoc($result)) {
									$fieldids[] = $row['id'];
								}
								mysql_free_result($result);

                                if (!empty($fieldids)) {
                                    $subscribe_contacts_fieldid = 0;
                                    $result = select_query('tblcustomfields', 'id', array('type' => 'client', 'fieldtype' => 'tickbox', 'sortorder' => 46309));
                                    if ($row = mysql_fetch_assoc($result)) {
                                        $subscribe_contacts_fieldid = $row['id'];
                                    }
                                    mysql_free_result($result);

                                    $default_format_fieldid = 0;
                                    $result = select_query('tblcustomfields', 'id', array('type' => 'client', 'fieldtype' => 'dropdown', 'sortorder' => 46307));
                                    if ($row = mysql_fetch_assoc($result)) {
                                        $default_format_fieldid = $row['id'];
                                    }
                                    mysql_free_result($result);

					    			$fieldids_str = implode(',', $fieldids);
					    			if ($ezconf->debug > 2) {
					    				logActivity("ezchimp_output: fieldids_str - $fieldids_str");
					    			}
									$query = "SELECT DISTINCT `relid` FROM `tblcustomfieldsvalues` WHERE `fieldid` IN ($fieldids_str)";
									$result = mysql_query($query);
									$subscribed = array();
									while ($row = mysql_fetch_assoc($result)) {
										$subscribed[$row['relid']] = 1;
									}
									mysql_free_result($result);
					    			if ($ezconf->debug > 2) {
					    				logActivity("ezchimp_output: subscribed - ".print_r($subscribed, true));
					    			}

									$activelists = unserialize($settings['activelists']);
									if ($ezconf->debug > 2) {
										logActivity("ezchimp_output: activelists - ".print_r($activelists, true));
									}

									$email_type = $settings['default_format'];
									$subscriptions = array();
									foreach ($activelists as $list => $groups) {
										if (!is_array($groups)) {
											$subscriptions[] = array('list' => $list);
										} else {
											$subscription_groupings = array();
											foreach ($groups as $maingroup => $groups) {
												$groups_str = '';
												foreach ($groups as $group => $alias) {
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
					    			$clients = array();
                                    $result = select_query('tblclients', 'id, firstname, lastname, email', array('status' => 'Active'));
					    			while ($row = mysql_fetch_assoc($result)) {
					    				$clientid = $row['id'];
					    				$firstname = $row['firstname'];
					    				$lastname = $row['lastname'];
					    				$email = $row['email'];

					    				if ($default_format_fieldid > 0) {
					    					/* Set default format for client */
					    					$query = "INSERT INTO `tblcustomfieldsvalues` (`fieldid`, `relid`, `value`) VALUES ($default_format_fieldid, $clientid, 'on')";
					    					mysql_query($query);
					    				}

					    				if (!isset($subscribed[$clientid])) {
					    					$clients[$clientid]['self'] = $row;
					    					/* Update database */
					    					foreach ($fieldids as $fieldid) {
					    						$query = "INSERT INTO `tblcustomfieldsvalues` (`fieldid`, `relid`, `value`) VALUES ($fieldid, $clientid, 'on')";
					    						mysql_query($query);
					    					}

					    					/* Update MailChimp */
											foreach ($subscriptions as $subscription) {
												_ezchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type, $ezvars);
											}
							    			if ($ezconf->debug > 2) {
							    				logActivity("ezchimp_output: subscribed client - $firstname $lastname <$email>");
							    			}
											if ('on' == $settings['subscribe_contacts']) {
												if ($subscribe_contacts_fieldid > 0) {
													/* Set subscribe contacts for client */
						    						$query = "INSERT INTO `tblcustomfieldsvalues` (`fieldid`, `relid`, `value`) VALUES ($subscribe_contacts_fieldid, $clientid, 'on')";
						    						mysql_query($query);
												}

												$query = "SELECT `id`, `firstname`, `lastname`, `email` FROM `tblcontacts` WHERE `userid`=$clientid";
												$contact_result = mysql_query($query);
												while ($contact = mysql_fetch_assoc($contact_result)) {
													$contactid = $contact['id'];
								    				$firstname = $contact['firstname'];
								    				$lastname = $contact['lastname'];
								    				$email = $contact['email'];
								    				$clients[$clientid]['contacts'][$contactid] = $contact;
													foreach ($subscriptions as $subscription) {
														_ezchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type, $ezvars);
													}
													if ($ezconf->debug > 2) {
														logActivity("ezchimp_output: subscribed contact - $firstname $lastname <$email>");
													}
												}
												mysql_free_result($contact_result);
											}
					    				} else {
							    			if ($ezconf->debug > 1) {
							    				logActivity("ezchimp_output: already subscribed - $firstname $lastname <$email>");
							    			}
					    				}
					    			}
									mysql_free_result($result);
					    			if (!empty($clients)) {
					    				echo '<div class="infobox"><strong>'.$LANG['empty_subscribed'].'</strong></div>';
					    				echo '<div class="tablebg"><table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">
	<tr>
		<th>'.$LANG['client_id'].'</th>
		<th>'.$LANG['contact_id'].'</th>
		<th>'.$LANG['firstname'].'</th>
		<th>'.$LANG['lastname'].'</th>
		<th>'.$LANG['email'].'</th>
	</tr>';
					    				foreach ($clients as $id => $details) {
					    					echo '
	<tr>
		<td><a href="clientssummary.php?userid='.$id.'">'.$id.'</a></td>
		<td>-</td>
		<td><a href="clientssummary.php?userid='.$id.'">'.$details['self']['firstname'].'</a></td>
		<td><a href="clientssummary.php?userid='.$id.'">'.$details['self']['lastname'].'</a></td>
		<td><a href="mailto:'.$details['self']['email'].'">'.$details['self']['email'].'</a></td>
	</tr>';
					    					if (!empty($details['contacts'])) {
					    						foreach ($details['contacts'] as $ctid => $contact) {
					    							echo '
	<tr>
		<td><a href="clientssummary.php?userid='.$id.'">'.$id.'</a></td>
		<td><a href="clientscontacts.php?userid='.$id.'&contactid='.$ctid.'">'.$ctid.'</a></td>
		<td><a href="clientscontacts.php?userid='.$id.'&contactid='.$ctid.'">'.$contact['firstname'].'</a></td>
		<td><a href="clientscontacts.php?userid='.$id.'&contactid='.$ctid.'">'.$contact['lastname'].'</a></td>
		<td><a href="mailto:'.$contact['email'].'">'.$contact['email'].'</a></td>
	</tr>';
					    						}
					    					}
					    				}
					    				echo '
</table></div>';
									} else {
						    			if ($ezconf->debug > 0) {
						    				logActivity("ezchimp_output: $action - No empty clients");
						    			}
						    			echo '<div class="errorbox"><strong>'.$LANG['no_empty_clients'].'</strong></div>';
									}
								} else {
					    			if ($ezconf->debug > 0) {
					    				logActivity("ezchimp_output: $action - No active lists/groups");
					    			}
					    			echo '<div class="errorbox"><strong>'.$LANG['no_active_lists'].'</strong></div>';
								}
		    					break;

                            case 'reset_to_autosubscribe':
                                $fieldids = array();
                                $result = select_query('tblcustomfields', 'id', array('type' => 'client', 'fieldtype' => 'tickbox', 'sortorder' => 46306));
                                while ($row = mysql_fetch_assoc($result)) {
                                    $fieldids[] = $row['id'];
                                }
                                mysql_free_result($result);

                                if (!empty($fieldids)) {
                                    $errors = array();

                                    $groupings = unserialize($settings['groupings']);
                                    if ($ezconf->debug > 0) {
                                        logActivity("reset_to_autosubscribe: groupings - " . print_r($groupings, true));
                                    }

                                    if ($result_clients = select_query('tblclients', 'COUNT(id) AS client_count', array('status' => 'Active'))) {
                                        $client_count = 0;
                                        if ($row = mysql_fetch_assoc($result_clients)) {
                                            $client_count = $row['client_count'];
                                        }
                                        mysql_free_result($result_clients);
                                        $offset = 0;
                                        $limit = 100;
                                        do {
                                            if ($ezconf->debug > 1) {
                                                logActivity("reset_to_autosubscribe: offset: $offset, limit: $limit, total: $client_count");
                                            }
                                            $query = "SELECT DISTINCT `id`, `firstname`, `lastname`, `email` FROM `tblclients` WHERE `status`='Active' ORDER BY `id` ASC LIMIT $offset, $limit";
                                            //$result_clients = select_query('tblclients', 'id, firstname, lastname, email', array('status' => 'Active'));
                                            $result_clients = mysql_query($query);
                                            while ($client = mysql_fetch_assoc($result_clients)) {
                                                $client_id = $client['id'];
                                                $firstname = $client['firstname'];
                                                $lastname = $client['lastname'];
                                                $email = $client['email'];
                                                $email_type = _ezchimp_client_email_type($client_id, $ezvars);
                                                //$client_subscribe_contacts = _ezchimp_client_subscribe_contacts($client_id, $ezvars);

                                                $productgroup_names = array();
                                                /* Check ordered domains if domains grouping available */
                                                if (!empty($groupings['Domains']) || !empty($groupings1['Domains'])) {
                                                    $result = select_query('tbldomains', 'id', array('userid' => $client_id));
                                                    if (mysql_num_rows($result) > 0) {
                                                        $productgroup_names['Domains'] = 1;
                                                    }
                                                }
                                                mysql_free_result($result);
                                                /* Check the ordered modules */
                                                $query = "SELECT DISTINCT `g`.`name` AS `gname` FROM `tblhosting` AS `h` JOIN `tblproducts` AS `p` ON `h`.`packageid`=`p`.`id` JOIN `tblproductgroups` AS `g` ON `p`.`gid`=`g`.`id` WHERE `h`.`userid`='" . $client_id . "'";
                                                $result = mysql_query($query);
                                                while ($productgroup = mysql_fetch_assoc($result)) {
                                                    $productgroup_names[$productgroup['gname']] = 1;
                                                }
                                                mysql_free_result($result);
                                                if ($ezconf->debug > 1) {
                                                    logActivity("reset_to_autosubscribe: Product groups for $firstname $lastname [$email] ($client_id) - " . print_r($productgroup_names, true));
                                                }
                                                $subscribe_list_groups = array();
                                                foreach ($productgroup_names as $productgroup_name => $one) {
                                                    if (!empty($groupings[$productgroup_name])) {
                                                        foreach ($groupings[$productgroup_name] as $list_id1 => $list_groupings) {
                                                            if ($ezconf->debug > 0) {
                                                                logActivity("reset_to_autosubscribe: subscription group1 ($list_id1) - " . print_r($list_groupings, true));
                                                            }
                                                            if (!(is_array($list_groupings))) {
                                                                if ($ezconf->debug > 0) {
                                                                    logActivity("reset_to_autosubscribe: empty main group");
                                                                }
                                                                if (!isset($subscribe_list_groups[$list_id1])) {
                                                                    $subscribe_list_groups[$list_id1] = array();
                                                                }
                                                                $query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306 AND `fieldoptions`='" . mysql_real_escape_string($list_id1) . "'";
                                                                $result = mysql_query($query);
                                                                $row = mysql_fetch_assoc($result);
                                                                $field_id = $row['id'];
                                                                if ($ezconf->debug > 0) {
                                                                    logActivity("reset_to_autosubscribe: subscription field3 - " . $row['id']);
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
                                                                    if ($ezconf->debug > 2) {
                                                                        logActivity("reset_to_autosubscribe: subscription update3");
                                                                    }
                                                                    $query = "UPDATE `tblcustomfieldsvalues` SET `value`='on' where `relid`=$client_id AND `fieldid`=$field_id";
                                                                    mysql_query($query);
                                                                } else if (!isset($subscribed1[$client_id])) {
                                                                    if ($ezconf->debug > 2) {
                                                                        logActivity("reset_to_autosubscribe: subscribed insert3 - " . print_r($subscribed1, true));
                                                                    }
                                                                    $query = "INSERT INTO `tblcustomfieldsvalues` (`fieldid`, `relid`, `value`) VALUES ($field_id, $client_id, 'on')";
                                                                    mysql_query($query);
                                                                } else {
                                                                    if ($ezconf->debug > 2) {
                                                                        logActivity("reset_to_autosubscribe: list subscribed3");
                                                                    }
                                                                }
                                                            } else {
                                                                if (!isset($subscribe_list_groups[$list_id1])) {
                                                                    $subscribe_list_groups[$list_id1] = $list_groupings;
                                                                    $fresh_list = true;
                                                                } else {
                                                                    $fresh_list = false;
                                                                }
                                                                foreach ($list_groupings as $maingroup => $groups) {
                                                                    if (!$fresh_list) {
                                                                        if (!isset($subscribe_list_groups[$list_id1][$maingroup])) {
                                                                            $subscribe_list_groups[$list_id1][$maingroup] = $groups;
                                                                        } else {
                                                                            $subscribe_list_groups[$list_id1][$maingroup] = array_unique(array_merge($subscribe_list_groups[$list_id1][$maingroup], $groups));
                                                                            if ($ezconf->debug > 4) {
                                                                                logActivity("reset_to_autosubscribe: New sub groups ($list_id1 > $maingroup): " . print_r($subscribe_list_groups[$list_id1][$maingroup], true));
                                                                            }
                                                                        }
                                                                    }
                                                                    foreach ($groups as $grps) {
                                                                        $query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306 AND `fieldname`='" . mysql_real_escape_string($grps) . "'";
                                                                        $result = mysql_query($query);
                                                                        $row = mysql_fetch_assoc($result);
                                                                        $field_id = $row['id'];
                                                                        if ($ezconf->debug > 0) {
                                                                            logActivity("reset_to_autosubscribe: subscription field1 - " . print_r($row['id'], true));
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
                                                                            if ($ezconf->debug > 2) {
                                                                                logActivity("reset_to_autosubscribe: subscribion update1");
                                                                            }
                                                                            $query = "UPDATE `tblcustomfieldsvalues` SET `value`='on' where `relid`=$client_id AND `fieldid`=$field_id";
                                                                            mysql_query($query);
                                                                        } else if (!isset($subscribed1[$client_id])) {
                                                                            if ($ezconf->debug > 2) {
                                                                                logActivity("reset_to_autosubscribe: subscribed insert1 - " . print_r($subscribed1, true));
                                                                            }
                                                                            $query = "INSERT INTO `tblcustomfieldsvalues` (`fieldid`, `relid`, `value`) VALUES ($field_id, $client_id, 'on')";
                                                                            mysql_query($query);
                                                                        } else {
                                                                            if ($ezconf->debug > 2) {
                                                                                logActivity("reset_to_autosubscribe: list subscribed");
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        $errors[] = "Product group [" . $productgroup_name . "] lists not found in map";
                                                    }
                                                }
                                                if ($ezconf->debug > 4) {
                                                    logActivity("reset_to_autosubscribe: Subscriptions for $firstname $lastname [$email] ($client_id) - " . print_r($subscribe_list_groups, true));
                                                }
                                                if (!empty($subscribe_list_groups)) {
                                                    foreach ($subscribe_list_groups as $list_id => $interest_groupings) {
                                                        $subscription_groupings = array();
                                                        foreach ($interest_groupings as $maingroup => $sub_groups) {
                                                            $sub_groups_str = implode(',', $sub_groups);
                                                            $subscription_groupings[] = array('name' => $maingroup, 'groups' => $sub_groups_str);
                                                        }
                                                        $subscription = array('list' => $list_id, 'grouping' => $subscription_groupings);
                                                        _ezchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type, $ezvars);
                                                        // TODO: subscribe contacts if configured
                                                    }
                                                }
                                            }
                                            mysql_free_result($result_clients);
                                            $offset += $limit;
                                        } while ($offset < $client_count);
                                    }
                                    if ($ezconf->debug > 0) {
                                        logActivity("reset_to_autosubscribe: errors - ".print_r($errors, true));
                                    }
                                    echo '<div class="infobox"><strong>'.$LANG['subscriptions_reset'].'</strong></div>';
                                } else {
                                    if ($ezconf->debug > 0) {
                                        logActivity("ezchimp_output: $action - No active lists/groups");
                                    }
                                    echo '<div class="errorbox"><strong>'.$LANG['no_active_lists'].'</strong></div>';
                                }
                                break;

                            default:
                                echo '<div class="errorbox"><strong>'.$LANG['invalid_action'].'</strong></div>';
                                break;
		    			}
	    			} else {
	    				echo '<div class="errorbox"><strong>'.$LANG['no_action'].'</strong></div>';
	    			}
	    		} else {
	    			echo '<form name="ezchimpTools" method="POST">
<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">
	<tr>
		<td class="fieldlabel">
			<input type="radio" name="action" value="subscribe_empty" class="ui-button ui-widget ui-state-default ui-corner-all" role="button" aria-disabled="false">
		</td>
		<td class="fieldarea">'.$LANG['subscribe_empty_desc'].'</td>
	</tr>
	<tr>
		<td class="fieldlabel">
			<input type="radio" name="action" value="reset_to_autosubscribe" class="ui-button ui-widget ui-state-default ui-corner-all" role="button" aria-disabled="false">
		</td>
		<td class="fieldarea">'.$LANG['reset_to_autosubscribe_desc'].'</td>
	</tr>
</table><p align="center"><input type="submit" value="'.$LANG['execute'].'" class="ui-button ui-widget ui-state-default ui-corner-all" role="button" aria-disabled="false"></p></form>';
	    		}
	    		break;

	    	case 'lists':
	    		$lists = $list_names = array();
	    		$params = array('apikey' => $apikey);

	    		$lists_result = _ezchimp_mailchimp_api('lists', $params, $ezvars);
	    		if ($ezconf->debug > 3) {
	    			logActivity("ezchimp_output: lists result - ".print_r($lists_result, true));
	    		}
	    		if (!empty($lists_result->data)) {
	    			foreach ($lists_result->data as $list) {
	    				$params['id'] = $list->id;
	    				$list_groupings = array();
	    				$maingroups = _ezchimp_mailchimp_api('listInterestGroupings', $params, $ezconf);
	    				if (!empty($maingroups)) {
	    					foreach ($maingroups as $maingroup) {
	    						$groups = array();
	    						foreach ($maingroup->groups as $group) {
	    							$groups[] = $group->name;
	    						}
	    						$list_groupings[$maingroup->name] = $groups;
	    					}
	    				}
	    				$lists[$list->name] = array('id' => $list->id, 'groupings' => $list_groupings);
                        $list_names[$list->id] = $list->name;
	    			}
	    		}
	    		if ($ezconf->debug > 2) {
	    			logActivity("ezchimp_output: lists - ".print_r($lists, true));
	    		}

	    		if (empty($lists)) {
	    			echo '<div class="errorbox"><strong>'.$LANG['create_list'].'</strong></div>';
	    		} else {
	    			$showorder = isset($settings['showorder']) ? $settings['showorder'] : '';
	    			if (!empty($settings['activelists'])) {
	    				$activelists = unserialize($settings['activelists']);
				    	if ($ezconf->debug > 1) {
				    		logActivity("ezchimp_output: activelists - ".print_r($activelists, true));
				    	}
	    			} else {
	    				$activelists = array();
	    			}
				    if (!empty($_POST)) {
		    			$saved = false;
                        $removed_webhooks = array();
				    	if (!empty($_POST['activelists'])) {
					    	if ($ezconf->debug > 4) {
					    		logActivity("ezchimp_output: POST - ".print_r($_POST, true));
					    	}
				    		$activelists_update = array();

				    		foreach ($_POST['activelists'] as $list) {
				    			$list_str = '';
				    			if (strpos($list, '^:') === false) {
				    				$list_parts = explode('%#', $list);
				    				$list_id = $list_parts[0];
				    				$list_name = $list_parts[1];
				    				$alias = ((!strcmp($_POST['aliases'][md5($list)],'Array'))||(empty($_POST['aliases'][md5($list)]))) ? $list_name : $_POST['aliases'][md5($list)];
					    			$activelists_update[$list_id] = $alias;
					    			$list_str = $list_id;
				    			} else {
					    			$parts = explode('^:', $list);
					    			if (!empty($parts[2])) {
					    				$list_parts = explode('%#', $parts[0]);
					    				$list_id = $list_parts[0];
					    				$list_name = $list_parts[1];
					    				$maingroup = $parts[1];
					    				$group = $parts[2];
					    				$alias = empty($_POST['aliases'][md5($list)]) ? $group : $_POST['aliases'][md5($list)];
					    				$activelists_update[$list_id][$maingroup][$group] = $alias;
					    				$list_str = $list_id.'^:'.$maingroup.'^:'.$group;
					    			}
				    			}
				    			if ('' != $list_str) {
                                    $_ADDONLANG = module_language_init();
				    				$query = "SELECT `id`, `fieldname` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306 AND `fieldoptions`='".mysql_real_escape_string($list_str)."'";
				    				$result = mysql_query($query);
				    				if (mysql_num_rows($result) > 0) {
				    					$row = mysql_fetch_assoc($result);
				    					/* Update field name if it has changed */
				    					if ($_ADDONLANG['subscribe_to'].$alias != $row['fieldname']) {
				    						$custom_field_id = $row['id'];
				    						$query = "UPDATE `tblcustomfields` SET `fieldname`='".mysql_real_escape_string($_ADDONLANG['subscribe_to'].$alias)."' WHERE `id`=$custom_field_id";
				    						mysql_query($query);
									    	if ($ezconf->debug > 1) {
									    		logActivity("ezchimp_output: alias update - ".$row['fieldname']." -> ".$_ADDONLANG['subscribe_to'].$alias);
									    	}
				    					}
				    				} else {
				    					/* Insert newly activated list */
				    					$query = "INSERT INTO `tblcustomfields` (`type`, `relid`, `fieldname`, `fieldtype`, `description`, `fieldoptions`, `regexpr`, `adminonly`, `required`, `showorder`, `showinvoice`, `sortorder`) VALUES ('client', 0, '".mysql_real_escape_string($_ADDONLANG['subscribe_to'].$alias)."', 'tickbox', '".mysql_real_escape_string($_ADDONLANG['subscribe_to_list'])."', '".mysql_real_escape_string($list_str)."', '', '', '', '$showorder', '', 46306)";
				    					mysql_query($query);
				    				}
				    			}
				    		}
					    	if ($ezconf->debug > 0) {
					    		logActivity("ezchimp_output: activelists update - ".print_r($activelists_update, true));
					    	}
			    			/* Remove deactivated lists and their subscriptions */
			    			foreach ($activelists as $list_id => $maingroups) {
                                /* Remove web hook */
                                if (!empty($vars['baseurl'])) {
                                    $params = array(
                                        'apikey' => $apikey,
                                        'id' => $list_id,
                                        'url' => $vars['baseurl']."/ezchimp_webhook.php"
                                    );
                                    _ezchimp_mailchimp_api('listWebhookDel', $params, $ezconf);
                                    $removed_webhooks[$list_id] = true;
                                }
			    				if (!is_array($maingroups)) {
			    					/* This is a list without groups */
			    					if (!isset($activelists_update[$list_id]) || is_array($activelists_update[$list_id])) {
			    						$list_str = $list_id;
			    						$query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306 AND `fieldoptions`='".mysql_real_escape_string($list_str)."'";
			    						$result = mysql_query($query);
					    				if (mysql_num_rows($result) > 0) {
					    					$row = mysql_fetch_assoc($result);
					    					$custom_field_id = $row['id'];
					    					$query = "DELETE FROM `tblcustomfieldsvalues` WHERE `fieldid`=$custom_field_id";
			    							mysql_query($query);
			    							$query = "DELETE FROM `tblcustomfields` WHERE `id`=$custom_field_id";
			    							mysql_query($query);
					    				}
			    						if ($ezconf->debug > 1) {
			    							logActivity("ezchimp_output: delete active - $list_str");
			    						}
			    					}
			    				} else {
			    					foreach ($maingroups as $maingroup => $groups) {
			    						foreach ($groups as $group => $alias) {
				    						if (!isset($activelists_update[$list_id][$maingroup][$group])) {
				    							$list_str = $list_id.'^:'.$maingroup.'^:'.$group;
					    						$query = "SELECT `id` FROM `tblcustomfields` WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306 AND `fieldoptions`='".mysql_real_escape_string($list_str)."'";
					    						$result = mysql_query($query);
							    				if (mysql_num_rows($result) > 0) {
							    					$row = mysql_fetch_assoc($result);
							    					$custom_field_id = $row['id'];
							    					$query = "DELETE FROM `tblcustomfieldsvalues` WHERE `fieldid`=$custom_field_id";
					    							mysql_query($query);
					    							$query = "DELETE FROM `tblcustomfields` WHERE `id`=$custom_field_id";
					    							mysql_query($query);
							    				}
					    						if ($ezconf->debug > 1) {
					    							logActivity("ezchimp_output: delete active - $list_str");
					    						}
				    						}
			    						}
			    					}
			    				}
			    			}
				    	}
                        $webhooks_failed = array();
                        /* Add web hook for active lists */
                        if (!empty($vars['baseurl'])) {
                            foreach (array_keys($activelists_update) as $list_id) {
                                if (!isset($removed_webhooks[$list_id])) {
                                    $params = array(
                                        'apikey' => $apikey,
                                        'id' => $list_id,
                                        'url' => $vars['baseurl']."/ezchimp_webhook.php"
                                    );
                                    _ezchimp_mailchimp_api('listWebhookDel', $params, $ezconf);
                                }
                                $params = array(
                                    'apikey' => $apikey,
                                    'id' => $list_id,
                                    'url' => $vars['baseurl']."/ezchimp_webhook.php",
                                    'actions' => array(
                                                        'subscribe' => false,
                                                        'unsubscribe' => true,
                                                        'profile' => false,
                                                        'cleaned' => false,
                                                        'upemail' => false,
                                                        'campaign' => false
                                                    )
                                );
                                if (!_ezchimp_mailchimp_api('listWebhookAdd', $params, $ezconf)) {
                                    $webhooks_failed[] = $list_id;
                                }
                            }
                        }
				    	$value = serialize($activelists_update);
				    	$query = "REPLACE INTO `mod_ezchimp` (`setting`, `value`) VALUES ('activelists', '$value')";
				    	if ($result = mysql_query($query)) {
				    		$saved = true;
				    	}
				    }

	    			echo '<br /><h2>'.$LANG['lists_groups'].'</h2><p>'.$LANG['lists_groups_desc'].'</p>';
	    			if (!empty($_POST)) {
	    				if ($saved) {
	    					echo '<div class="infobox"><strong>'.$LANG['saved'].'</strong><br>'.$LANG['saved_desc'].'</div>';
                            if (!empty($webhooks_failed)) {
                                echo '<div class="errorbox"><strong>'.$LANG['webhooks_failed'].'</strong><br>';
                                echo implode(' ', $webhooks_failed);
                                echo '<br>('.$LANG['webhooks_failed_desc'].')</div>';
                            }
	    					$activelists = $activelists_update;
	    				} else {
	    					echo '<div class="errorbox"><strong>'.$LANG['save_failed'].'</strong><br>'.$LANG['save_failed_desc'].'</div>';
	    				}
	    			}
	    			echo '<form name="ezchimpLists" method="POST"><table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">
	<tr>
		<th width="20%">'.$LANG['alias'].'</th>
		<th>'.$LANG['interest_groups'].'</th>
	</tr>';
	    			$listnames = array();
	    			foreach ($lists as $name => $list) {
	    				$listnames[$list['id']] = $name;
	    				$no_groups = true;
	    				foreach ($list['groupings'] as $maingroup) {
	    					if (!empty($maingroup)) {
	    						$no_groups = false;
	    						break;
	    					}
	    				}
	    				if ($no_groups) {
	    					$interest_group = $list['id'].'%#'.$name;
	    					echo '<tr>
	    <td width="20%" class="fieldlabel"><input type="text" name="aliases['.md5($interest_group).']" value="'.$activelists[$list['id']].'" /></td>
	    <td class="fieldarea"><input type="checkbox" name="activelists[]" value="'.$interest_group.'"';
	    					if (isset($activelists[$list['id']])) {
	    						echo ' checked="checked"';
	    					}
	    					echo ' /> '.$name.'</td></tr>';
	    				} else {
	    					foreach ($list['groupings'] as $maingroup => $groups) {
	    						foreach ($groups as $group) {
	    							$interest_group = $list['id'].'%#'.$name.'^:'.$maingroup.'^:'.$group;
	    							echo '<tr>
	    <td width="20%" class="fieldlabel"><input type="text" name="aliases['.md5($interest_group).']" value="'.$activelists[$list['id']][$maingroup][$group].'" /></td>
	    <td class="fieldarea"><input type="checkbox" name="activelists[]" value="'.$interest_group.'"';
	    							if (isset($activelists[$list['id']][$maingroup][$group])) {
	    								echo ' checked="checked"';
	    							}
	    							echo ' /> '.$name.' &gt; '.$maingroup.' &gt; '.$group.'</td></tr>';
	    						}
	    					}
	    				}
	    			}
	    			if ($ezconf->debug > 1) {
	    				logActivity("ezchimp_output: listnames - ".print_r($listnames, true));
	    			}
	    			$value = serialize($listnames);
	    			$query = "REPLACE INTO `mod_ezchimp` (`setting`, `value`) VALUES ('listnames', '".mysql_real_escape_string($value)."')";
	    			$result = mysql_query($query);
	    			echo '
	</tr>
</table><p align="center"><input type="submit" value="'.$LANG['save'].'" class="ui-button ui-widget ui-state-default ui-corner-all" role="button" aria-disabled="false"></p></form>';
	    		}
	    		break;

           case 'autosubscribe':
                $productgroups = array('Domains');
                $flag=false;
	    		$query = "SELECT `name` FROM `tblproductgroups`";
	    		$result = mysql_query($query);
	    		while($row = mysql_fetch_assoc($result)) {
	    			$productgroups[] = $row['name'];
	    		}
	    		mysql_free_result($result);

	    		if (empty($productgroups)) {
	    			echo '<div class="errorbox"><strong>'.$LANG['add_product_groups'].' <a href="configproducts.php">'.$LANG['product_setup'].'</a></strong></div>';
	    		} else {
		    		$lists = $listnames = array();
		    		$params = array('apikey' => $apikey);
		    		$lists_result = _ezchimp_mailchimp_api('lists', $params, $ezconf);
		    		if ($ezconf->debug > 3) {
		    			logActivity("ezchimp_output: lists result - ".print_r($lists_result, true));
		    		}

                    if (!empty($settings['activelists'])) {
                        $activelists = unserialize($settings['activelists']);
                        if (!empty($activelists)) {
                            foreach ($activelists as $listid => $list){
                                if (!empty($lists_result->data)) {
                                    foreach ($lists_result->data as $listname) {
                                        if(!(strcmp($listname->id,$listid))){
                                            $lists[$listname->name] = array('id' => $listid, 'groupings' => $list);
                                        }
                                    }
                                }
                                if ($ezconf->debug > 0) {
                                    logActivity("ezchimp_output: listid - " . print_r($listid, true));
                                }
                            }
                        }
                    }
                    if ($ezconf->debug > 0) {
                        logActivity("ezchimp_output: activelist - " . print_r($lists, true));
                    }

                    if (empty($lists)) {
		    			echo '<div class="errorbox"><strong>'.$LANG['create_list'].'</strong></div>';
                    } else {
                        if (!empty($_POST)) {
                            $flag=false;
                            if ($ezconf->debug > 4) {
                                logActivity("ezchimp_output: POST - " . print_r($_POST, true));
                            }
                            $groupings = $groupings1 = array();
                            $saved = $saved1 = false;
                            if (!empty($_POST['groupings'])) {
                                foreach ($_POST['groupings'] as $grouping) {
                                    $parts = explode('^:', $grouping);
                                    if (!empty($parts[3])) {
                                        $groupings[$parts[0]][$parts[1]][$parts[2]][] = $parts[3];
                                    } else if (!empty($parts[1])) {
                                        $groupings[$parts[0]][$parts[1]] = $parts[1];
                                    }
                                }
                            }
                            if ($ezconf->debug > 0) {
                                logActivity("ezchimp_output: groupings update - " . print_r($groupings, true));
                            }

                            if (!empty($_POST['groupings1'])) {
                                foreach ($_POST['groupings1'] as $grouping) {
                                    $parts = explode('^:', $grouping);
                                    if (!empty($parts[3])) {
                                        $groupings1[$parts[0]][$parts[1]][$parts[2]][] = $parts[3];
                                    } else if (!empty($parts[1])) {
                                        $groupings1[$parts[0]][$parts[1]] = $parts[1];
                                    }
                                }
                            }
                            foreach ($groupings as $lid => $l1) {
                                foreach ($groupings1 as $lid2 => $l2) {
                                    foreach ($l1 as $m1) {
                                        if (!(is_array($m1))) {
                                            foreach ($l2 as $m2) {
                                                if (!(is_array($m2))) {
                                                    if (!(strcmp($m1, $m2))) {
                                                        if ($ezconf->debug > 0) {
                                                            logActivity("ezchimp_output: common grps1 - " . print_r($m1, true));
                                                        }
                                                        $flag = true;
                                                    }
                                                }
                                            }
                                        }
                                        foreach ($l2 as $m2) {
                                            foreach ($m1 as $g1) {
                                                foreach ($m2 as $g2) {
                                                    foreach ($g1 as $n1) {
                                                        foreach ($g2 as $n2) {
                                                            if (!(strcmp($n1, $n2))) {
                                                                $flag = true;
                                                                if ($ezconf->debug > 0) {
                                                                    logActivity("ezchimp_output: common grps2 - " . print_r($n1, true));
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            if ($ezconf->debug > 0) {
                                logActivity("ezchimp_output: subscribed update - " . print_r($groupings, true));
                            }
                            $value = serialize($groupings);
                            $query = "REPLACE INTO `mod_ezchimp` (`setting`, `value`) VALUES ('groupings', '$value')";
                            if ($result = mysql_query($query)) {
                                $saved = true;
                            }
                            if ($ezconf->debug > 0) {
                                logActivity("ezchimp_output: unsubscribed update - " . print_r($groupings1, true));
                            }
                            $value = serialize($groupings1);
                            $query = "REPLACE INTO `mod_ezchimp` (`setting`, `value`) VALUES ('unsubscribe_groupings', '$value')";
                            if ($result = mysql_query($query)) {
                                $saved1 = true;
                            }
                        } else {
                            $groupings = unserialize($settings['groupings']);
                            $groupings1 = unserialize($settings['unsubscribe_groupings']);
                        }
                        echo '<br /><h2>'.$LANG['product_interest_grouping'].'</h2><p>'.$LANG['product_interest_grouping_desc'].'</p>';
                        if (!empty($_POST)) {
                            if ($saved || $saved1) {
                                echo '<div class="infobox"><strong>'.$LANG['saved'].'</strong><br>'.$LANG['saved_desc'].'</div>';
                            } else {
                                echo '<div class="errorbox"><strong>'.$LANG['save_failed'].'</strong><br>'.$LANG['save_failed_desc'].'</div>';
                            }
                            if ($flag) {
                                if ($ezconf->debug > 0) {
                                    logActivity("ezchimp_output: common groups" );
                                }
                                echo '<div class="infobox"><strong>'.$LANG['common select'].'</strong></div>';
                            }
                        } else if (!empty($settings['groupings'])) {
                            $groupings = unserialize($settings['groupings']);
                            $groupings1= unserialize($settings['unsubscribe_groupings']);
                        }
                        if ($ezconf->debug > 0) {
                            logActivity("ezchimp_output: subscribe groupings - ".print_r($groupings, true));
                            logActivity("ezchimp_output: unsubscribe groupings - ".print_r($groupings1, true));
                        }
                        echo '<form name="ezchimpGrouing" method="POST"><table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">

	<tr>
		<th width="10%">'.$LANG['product_groups'].'</th>
		<th colspan="2" width="20%">'.$LANG['interest_groups'].'</th>
	</tr>';
                        echo '<tr>
        <th width="10%">&nbsp</th>
		<th align="left">'.$LANG['unsubscribe'].'</th>
		<th align="left">'.$LANG['subscribe'].'</th>
	</tr>';

                        foreach ($productgroups as $productgroup) {
                            echo '
	<tr>
		<td align="center" width="10%" class="fieldlabel">';
                            if ('Domains' == $productgroup) {
                                echo $LANG['Domains'];
                            } else {
                                echo $productgroup;
                            }
                            echo '</td>
                        <td width="10%" class="fieldarea">
                        <select class="grouping1" multiple="multiple" name="groupings1[]">';
                        foreach ($lists as $list) {
                            $no_groups = true;
                            foreach ($list['groupings'] as $maingroup) {
                                if (!empty($maingroup)) {
                                    $no_groups = false;
                                    break;
                                }
                            }
                            if ($no_groups) {
                                echo '<option value="'.$productgroup.'^:'.$list['id'].'"';
                                if (isset($groupings1[$productgroup][$list['id']])) {
                                    echo ' selected="selected"';
                                }
                                echo '> '.$list['groupings'].'</option>';
                            } else {
                                foreach ($list['groupings'] as $maingroup => $groups) {
                                    foreach ($groups as $group) {
                                        echo '<option value="'.$productgroup.'^:'.$list['id'].'^:'.$maingroup.'^:'.$group.'"';
                                        if (!empty($groupings1[$productgroup][$list['id']][$maingroup]) && in_array($group, $groupings1[$productgroup][$list['id']][$maingroup])) {
                                            echo ' selected="selected"';
                                        }
                                        echo '> '.$group.' </option>';
                                    }
                                }
                            }
                        }
                        echo '</select>
                        </td>
                        <td width="10%" class="fieldarea">';
                            echo '<select class="groupings" multiple="multiple" name="groupings[]">';
                            foreach ($lists as $list) {
                                $no_groups = true;
                                foreach ($list['groupings'] as $maingroup) {
                                    if (!empty($maingroup)) {
                                        $no_groups = false;
                                        break;
                                    }
                                }
                                if ($no_groups) {
                                    echo '<option value="'.$productgroup.'^:'.$list['id'].'"';
                                    if (isset($groupings[$productgroup][$list['id']])) {
                                        echo ' selected="selected"';
                                    }
                                    echo '> '.$list['groupings'].'</option>';
                                }
                                else{
                                    foreach ($list['groupings'] as $maingroup => $groups) {
                                        foreach ($groups as $group) {
                                            echo '<option value="'.$productgroup.'^:'.$list['id'].'^:'.$maingroup.'^:'.$group.'"';
                                            if (!empty($groupings[$productgroup][$list['id']][$maingroup]) && in_array($group, $groupings[$productgroup][$list['id']][$maingroup])) {
                                                echo ' selected="selected"';
                                            }
                                            echo '> '.$group.' </option>';
                                        }
                                    }
                                }
                            }
                            echo ' </select>';
                        }
                        echo '</td>
	</tr>
</table><p align="center"><input type="submit" value="'.$LANG['save'].'" class="ui-button ui-widget ui-state-default ui-corner-all" role="button" aria-disabled="false"></p></form>';
                    }
                }
	    		break;

	    	default:
			    if (!empty($_POST)) {
	    			$saved = true;
	    			$settings_update = array();

			    	if (isset($_POST['double_optin']) && ('on' == $_POST['double_optin'])) {
			    		$settings_update['double_optin'] = 'on';
			    	} else {
			    		$settings_update['double_optin'] = '';
			    	}

			    	if (isset($_POST['delete_member']) && ('on' == $_POST['delete_member'])) {
			    		$settings_update['delete_member'] = 'on';
			    	} else {
			    		$settings_update['delete_member'] = '';
			    	}

			    	if (isset($_POST['send_goodbye']) && ('on' == $_POST['send_goodbye'])) {
			    		$settings_update['send_goodbye'] = 'on';
			    	} else {
			    		$settings_update['send_goodbye'] = '';
			    	}

			    	if (isset($_POST['send_notify']) && ('on' == $_POST['send_notify'])) {
			    		$settings_update['send_notify'] = 'on';
			    	} else {
			    		$settings_update['send_notify'] = '';
			    	}

			    	if (isset($_POST['format_select']) && ('on' == $_POST['format_select'])) {
			    		$settings_update['format_select'] = 'on';
			    		if (isset($settings_update['showorder'])) {
			    			$showorder = $settings_update['showorder'];
			    		} else if (isset($settings['showorder'])) {
			    			$showorder = $settings['showorder'];
			    		} else {
			    			$showorder = '';
			    		}
						$query = "UPDATE `tblcustomfields` SET `adminonly`='', `showorder`='$showorder' WHERE `type`='client' AND `fieldname`='".mysql_real_escape_string($LANG['email_format'])."' AND `fieldtype`='dropdown' AND `sortorder`=46307";
						$result = mysql_query($query);
			    	} else {
			    		$settings_update['format_select'] = '';
						$query = "UPDATE `tblcustomfields` SET `adminonly`='on', `showorder`='' WHERE `type`='client' AND `fieldname`='".mysql_real_escape_string($LANG['email_format'])."' AND `fieldtype`='dropdown' AND `sortorder`=46307";
						$result = mysql_query($query);
			    	}

			    	if (isset($_POST['subscribe_contacts']) && ('on' == $_POST['subscribe_contacts'])) {
			    		$settings_update['subscribe_contacts'] = 'on';
			    		if (isset($settings_update['showorder'])) {
			    			$showorder = $settings_update['showorder'];
			    		} else if (isset($settings['showorder'])) {
			    			$showorder = $settings['showorder'];
			    		} else {
			    			$showorder = '';
			    		}
						$query = "UPDATE `tblcustomfields` SET `adminonly`='', `showorder`='$showorder' WHERE `type`='client' AND `fieldname`='".mysql_real_escape_string($LANG['subscribe_all_contacts'])."' AND `fieldtype`='tickbox' AND `sortorder`=46309";
						$result = mysql_query($query);
			    	} else {
			    		$settings_update['subscribe_contacts'] = '';
						$query = "UPDATE `tblcustomfields` SET `adminonly`='on', `showorder`='' WHERE `type`='client' AND `fieldname`='".mysql_real_escape_string($LANG['subscribe_all_contacts'])."' AND `fieldtype`='tickbox' AND `sortorder`=46309";
						$result = mysql_query($query);
			    	}

			    	if (isset($_POST['interest_select']) && ('on' == $_POST['interest_select'])) {
			    		$settings_update['interest_select'] = 'on';
			    		if (isset($settings_update['showorder'])) {
			    			$showorder = $settings_update['showorder'];
			    		} else if (isset($settings['showorder'])) {
			    			$showorder = $settings['showorder'];
			    		} else {
			    			$showorder = '';
			    		}
						$query = "UPDATE `tblcustomfields` SET `adminonly`='', `showorder`='$showorder' WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306";
						$result = mysql_query($query);
			    	} else {
			    		$settings_update['interest_select'] = '';
						$query = "UPDATE `tblcustomfields` SET `adminonly`='on', `showorder`='' WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder`=46306";
						$result = mysql_query($query);
			    	}

			    	if (isset($_POST['showorder']) && ('on' == $_POST['showorder'])) {
			    		$settings_update['showorder'] = 'on';
						$query = "UPDATE `tblcustomfields` SET `showorder`='on' WHERE `adminonly`='' AND `type`='client' AND `fieldtype`='tickbox' AND `sortorder` IN (46306, 46307, 46309)";
						$result = mysql_query($query);
			    	} else {
			    		$settings_update['showorder'] = '';
						$query = "UPDATE `tblcustomfields` SET `showorder`='' WHERE `type`='client' AND `fieldtype`='tickbox' AND `sortorder` IN (46306, 46307, 46309)";
						$result = mysql_query($query);
			    	}

			    	if (isset($_POST['default_subscribe']) && ('on' == $_POST['default_subscribe'])) {
			    		$settings_update['default_subscribe'] = 'on';
			    	} else {
			    		$settings_update['default_subscribe'] = '';
			    	}

			    	if (isset($_POST['default_format']) && (in_array($_POST['default_format'], array('html', 'text', 'mobile')))) {
			    		$settings_update['default_format'] = $_POST['default_format'];
			    	} else {
			    		$settings_update['default_format'] = 'html';
			    	}

			    	if (isset($_POST['default_subscribe_contact']) && ('on' == $_POST['default_subscribe_contact'])) {
			    		$settings_update['default_subscribe_contact'] = 'on';
			    	} else {
			    		$settings_update['default_subscribe_contact'] = '';
			    	}

			    	if ($ezconf->debug > 0) {
			    		logActivity("ezchimp_output: module settings update - ".print_r($settings_update, true));
			    	}

			    	foreach ($settings_update as $setting => $value) {
			    		$query = "REPLACE INTO `mod_ezchimp` (`setting`, `value`) VALUES ('$setting', '$value')";
			    		if (!($result = mysql_query($query))) {
			    			$saved = false;
			    			break;
			    		}
			    	}
			    }

				echo '<br /><h2>'.$LANG['settings'].'</h2>';
				if (!empty($_POST)) {
					if ($saved) {
						$settings = $settings_update;
						echo '<div class="infobox"><strong>'.$LANG['saved'].'</strong><br>'.$LANG['saved_desc'].'</div>';
					} else {
						echo '<div class="errorbox"><strong>'.$LANG['save_failed'].'</strong><br>'.$LANG['save_failed_desc'].'</div>';
					}
				}
				echo '<form name="ezchimpConf" method="POST"><table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">
	<tr>
		<td width="20%" class="fieldlabel">'.$LANG['double_optin'].'</td>
		<td class="fieldarea"><input type="checkbox" name="double_optin" value="on"';
			    if (isset($settings['double_optin']) && ('on' == $settings['double_optin'])) {
			    	echo ' checked="checked"';
			    }
				echo ' /> '.$LANG['double_optin_desc'].'</td>
	</tr>
	<tr>
		<td width="20%" class="fieldlabel">'.$LANG['delete_member'].'</td>
		<td class="fieldarea"><input type="checkbox" name="delete_member" value="on"';
			    if (isset($settings['delete_member']) && ('on' == $settings['delete_member'])) {
			    	echo ' checked="checked"';
			    }
				echo ' /> '.$LANG['delete_member_desc'].'</td>
	</tr>
	<tr>
		<td width="20%" class="fieldlabel">'.$LANG['send_goodbye'].'</td>
		<td class="fieldarea"><input type="checkbox" name="send_goodbye" value="on"';
			    if (isset($settings['send_goodbye']) && ('on' == $settings['send_goodbye'])) {
			    	echo ' checked="checked"';
			    }
				echo ' /> '.$LANG['send_goodbye_desc'].'</td>
	</tr>
	<tr>
		<td width="20%" class="fieldlabel">'.$LANG['send_notify'].'</td>
		<td class="fieldarea"><input type="checkbox" name="send_notify" value="on"';
			    if (isset($settings['send_notify']) && ('on' == $settings['send_notify'])) {
			    	echo ' checked="checked"';
			    }
				echo ' /> '.$LANG['send_notify_desc'].'</td>
	</tr>
	<tr>
		<td width="20%" class="fieldlabel">'.$LANG['interest_select'].'</td>
		<td class="fieldarea"><input type="checkbox" name="interest_select" value="on"';
			    if (isset($settings['interest_select']) && ('on' == $settings['interest_select'])) {
			    	echo ' checked="checked"';
			    }
				echo ' /> '.$LANG['interest_select_desc'].'</td>
	</tr>
	<tr>
		<td width="20%" class="fieldlabel">'.$LANG['allow_format_select'].'</td>
		<td class="fieldarea"><input type="checkbox" name="format_select" value="on"';
			    if (isset($settings['format_select']) && ('on' == $settings['format_select'])) {
			    	echo ' checked="checked"';
			    }
				echo ' /> '.$LANG['allow_format_select_desc'].'</td>
	</tr>
	<tr>
		<td width="20%" class="fieldlabel">'.$LANG['subscribe_contacts'].'</td>
		<td class="fieldarea"><input type="checkbox" name="subscribe_contacts" value="on"';
			    if (isset($settings['subscribe_contacts']) && ('on' == $settings['subscribe_contacts'])) {
			    	echo ' checked="checked"';
			    }
				echo ' /> '.$LANG['subscribe_contacts_desc'].'</td>
	</tr>
	<tr>
		<td class="fieldlabel">'.$LANG['showorder'].'</td>
		<td class="fieldarea"><input type="checkbox" name="showorder" value="on"';
			    if (isset($settings['showorder']) && ('on' == $settings['showorder'])) {
			    	echo ' checked="checked"';
			    }
				echo ' /> '.$LANG['showorder_desc'].'</td>
	</tr>
	<tr>
		<td class="fieldlabel">'.$LANG['default_subscribe'].'</td>
		<td class="fieldarea"><input type="checkbox" name="default_subscribe" value="on"';
			    if (isset($settings['default_subscribe']) && ('on' == $settings['default_subscribe'])) {
			    	echo ' checked="checked"';
			    }
				echo ' /> '.$LANG['default_subscribe_desc'].'</td>
	</tr>
	<tr>
		<td class="fieldlabel">'.$LANG['default_format'].'</td>
		<td class="fieldarea">
			<select name="default_format">
				<option value="html"';
			    if (isset($settings['default_format']) && ('html' == $settings['default_format'])) {
			    	echo ' selected="selected"';
			    }
				echo '>HTML</option>
				<option value="mobile"';
			    if (isset($settings['default_format']) && ('mobile' == $settings['default_format'])) {
			    	echo ' selected="selected"';
			    }
				echo '>Mobile</option>
				<option value="text"';
			    if (isset($settings['default_format']) && ('text' == $settings['default_format'])) {
			    	echo ' selected="selected"';
			    }
				echo '>Text</option>
			</select> '.$LANG['default_format_desc'].'
		</td>
	</tr>
	<tr>
		<td class="fieldlabel">'.$LANG['default_subscribe_contact'].'</td>
		<td class="fieldarea"><input type="checkbox" name="default_subscribe_contact" value="on"';
			    if (isset($settings['default_subscribe_contact']) && ('on' == $settings['default_subscribe_contact'])) {
			    	echo ' checked="checked"';
			    }
				echo ' /> '.$LANG['default_subscribe_contact_desc'].'</td>
	</tr>
</table><p align="center"><input type="submit" value="'.$LANG['save'].'" class="ui-button ui-widget ui-state-default ui-corner-all" role="button" aria-disabled="false"></p></form>';
				break;
    	}
    }

}

//function ezchimp_sidebar($vars) {
//
//    $modulelink = $vars['modulelink'];
//    $LANG = $vars['_lang'];
//
//    $sidebar = '<ul class="menu">
//        <li><a href="'.$modulelink.'">'.$LANG['settings'].'</a></li>
//        <li><a href="'.$modulelink.'&page=lists">'.$LANG['lists_groups'].'</a></li>
//        <li><a href="'.$modulelink.'&page=status">'.$LANG['status'].'</a></li>
//        <li><a href="'.$modulelink.'&page=tools">'.$LANG['tools'].'</a></li>
//    </ul>';
//    return $sidebar;
//
//}


/**
 * Function for calling MailChimp API
 *
 * @param string
 * @param string
 * @param string
 * @param string
 */
function _ezchimp_mailchimp_api($method, $params, &$ezvars) {
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

	if ($ezvars->debug > 0) {
		logActivity("_ezchimp_mailchimp_api: URL - $submit_url, Payload - ".htmlentities($payload).", Response - ".htmlentities($retval));
	}
	return json_decode($retval);
}


function _ezchimp_unsubscribe($subscription, $email, &$ezvars) {
    $params = array(
        'id' => $subscription['list'],
        'email_address' => $email,
        'apikey' => $ezvars->config['apikey'],
        'delete_member' => (isset($ezvars->settings['delete_member']) && ('on' == $ezvars->settings['delete_member'])) ? true : false,
        'send_goodbye' => (isset($ezvars->settings['send_goodbye']) && ('on' == $ezvars->settings['send_goodbye'])) ? true : false,
        'send_notify' => (isset($ezvars->settings['send_notify']) && ('on' == $ezvars->settings['send_notify'])) ? true : false,
    );
    _ezchimp_mailchimp_api('listUnsubscribe', $params, $ezvars);
}


function _ezchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type='html', &$ezvars) {
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
			'apikey' => $ezvars->config['apikey'],
			'email_address' => $email,
			'email_type' => $email_type,
			'double_optin' => (isset($ezvars->settings['double_optin']) && ('on' == $ezvars->settings['double_optin'])) ? true : false,
			'merge_vars' => $merge_vars,
			'update_existing' => true,
			'replace_interests' => true,
			);
	_ezchimp_mailchimp_api('listSubscribe', $params, $ezvars);
}

/**
 * Return ezchimp config
 */
function _ezchimp_config(&$ezconf) {
	$config = array();
	$result = select_query('tbladdonmodules', 'setting, value', array('module' => 'ezchimp'));
	while ($row = mysql_fetch_assoc($result)) {
		$config[$row['setting']] = $row['value'];
	}
	mysql_free_result($result);
	if (isset($config['debug'])) {
		$ezconf->debug = intval($config['debug']);
	}
	if ($ezconf->debug > 0) {
		logActivity("_ezchimp_config: module config - ".print_r($config, true));
	}
	return $config;
}

/**
 * Return ezchimp settings
 */
function _ezchimp_settings(&$ezconf) {
	$settings = array();
	$result = select_query('mod_ezchimp', 'setting, value');
	while($row = mysql_fetch_assoc($result)) {
		$settings[$row['setting']] = $row['value'];
	}
	mysql_free_result($result);
	if ($ezconf->debug > 0) {
		logActivity("_ezchimp_settings: ".print_r($settings, true));
	}
	return $settings;
}

function _ezchimp_listgroup_subscriptions($client_id, &$ezvars) {
    $fields = array();
    $result = select_query('tblcustomfields', 'id, fieldoptions', array('type' => 'client', 'fieldtype' => 'tickbox', 'sortorder' => 46306));
    while ($row = mysql_fetch_assoc($result)) {
        $fields[$row['id']] = $row['fieldoptions'];
    }
    mysql_free_result($result);
    if ($ezvars->debug > 2) {
        logActivity("_ezchimp_listgroup_subscriptions: fields - ".print_r($fields, true));
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
    if ($ezvars->debug > 2) {
        logActivity("_ezchimp_listgroup_subscriptions: list_groups - ".print_r($list_groups, true));
    }

    $subscriptions = array();
    $all_lists = array();
    $params = array('apikey' => $ezvars->config['apikey']);
    $lists_result = _ezchimp_mailchimp_api('lists', $params, $ezvars);
    if (!empty($lists_result->data)) {
        foreach ($lists_result->data as $list) {
            $all_lists[$list->id] = 1;
        }
    }
    if ($ezvars->debug > 2) {
        logActivity("_ezchimp_listgroup_subscriptions: all_lists - ".print_r($all_lists, true));
    }

    foreach ($list_groups as $list => $groups) {
        unset($all_lists[$list]);
        if (empty($groups)) {
            $subscriptions[] = array('list' => $list);
        } else {
            $subscription_groupings = array();
            $all_groups = array();
            $params['id'] = $list;
            $groupings = _ezchimp_mailchimp_api('listInterestGroupings', $params, $ezvars);
            if (!empty($groupings)) {
                foreach ($groupings as $grouping) {
                    $all_groups[$grouping->name] = 1;
                }
            }
            if ($ezvars->debug > 2) {
                logActivity("_ezchimp_listgroup_subscriptions: all_groups - ".print_r($all_groups, true));
            }
            if ($ezvars->debug > 2) {
                logActivity("_ezchimp_listgroup_subscriptions: interest_groups - ".print_r($groupings, true));
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
                if ($ezvars->debug > 2) {
                    logActivity("_ezchimp_listgroup_subscriptions: all_groups2 - ".print_r($gr, true));
                }
            }
//            if (!empty($all_groups)) {
//                foreach ($all_groups as $maingroup => $one) {
//                    $subscription_groupings[] = array('name' => $maingroup, 'groups' => '');
//                    if ($ezvars->debug > 3) {
//                        logActivity("_ezchimp_listgroup_subscriptions: empty main group - $maingroup");
//                    }
//                }
//            }
            if (!empty($subscription_groupings)) {
                $subscriptions[] = array('list' => $list, 'grouping' => $subscription_groupings);
            }
        }
    }
    if ($ezvars->debug > 3) {
        logActivity("_ezchimp_listgroup_subscriptions: remaining all_lists - ".print_r($all_lists, true));
    }
    foreach ($all_lists as $list => $one) {
        $subscriptions[] = array('list' => $list, 'unsubscribe' => 1);
    }
    if ($ezvars->debug > 1) {
        logActivity("_ezchimp_listgroup_subscriptions: subscriptions - ".print_r($subscriptions, true));
    }

    return $subscriptions;
}

function _ezchimp_client_email_type($client_id, &$ezvars) {
    $email_type = $ezvars->settings['default_format'];

    $field_id = 0;
    $result = select_query('tblcustomfields', 'id', array('type' => 'client', 'fieldtype' => 'dropdown', 'sortorder' => 46307));
    if ($row = mysql_fetch_assoc($result)) {
        $field_id = $row['id'];
    }
    mysql_free_result($result);
    if ($ezvars->debug > 1) {
        logActivity("_ezchimp_client_email_type: field_id - $field_id");
    }

    if ($field_id > 0) {
        $result = select_query('tblcustomfieldsvalues', 'value', array('fieldid' => $field_id, 'relid' => $client_id));
        if ($row = mysql_fetch_assoc($result)) {
            $et = strtolower($row['value']);
            if (('html' == $et) || ('text' == $et)) {
                $email_type = $et;
            }
        }
        mysql_free_result($result);
        if ($ezvars->debug > 2) {
            logActivity("_ezchimp_client_email_type: email_type - $email_type");
        }
    }

    return $email_type;
}

function _ezchimp_client_subscribe_contacts($client_id, &$ezvars) {
    $subscribecontacts = '';

    $field_id = 0;
    $result = select_query('tblcustomfields', 'id', array('type' => 'client', 'fieldtype' => 'tickbox', 'sortorder' => 46309));
    if ($row = mysql_fetch_assoc($result)) {
        $field_id = $row['id'];
    }
    mysql_free_result($result);
    if ($ezvars->debug > 1) {
        logActivity("_ezchimp_client_subscribe_contacts: field_id - $field_id");
    }

    if ($field_id >0) {
        $result = select_query('tblcustomfieldsvalues', 'value', array('fieldid' => $field_id, 'relid' => $client_id));
        if ($row = mysql_fetch_assoc($result)) {
            $subscribecontacts = $row['value'];
        }
        mysql_free_result($result);
        if ($ezvars->debug > 2) {
            logActivity("_ezchimp_client_subscribe_contacts: subscribecontacts - $subscribecontacts");
        }
    }

    return $subscribecontacts;
}
