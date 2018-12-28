<?php
/*
**********************************************

* ezchimp - MailChimp Newsletter integration *

Author: Sunjith P S
License: GPLv3

Copyright AdMod Technologies Pvt Ltd
www.admod.com www.supportmonk.com www.ezeelogin.com

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

use Illuminate\Database\Capsule\Manager as Capsule;
require_once(__DIR__."/mailchimp.php");

define('EMAILS_LIMIT', 25);

class EzchimpConf {
    public $debug = 0;
}
class EzchimpAllVars {
    public $debug = 0;
    public $config = [];
    public $settings = [];
}

function ezchimp_config() {
    $config = [
        "name" => "MailChimp Newsletter",
        "description" => "Integrates with MailChimp. Supports subscribe/unsubscribe, multiple mailing lists and interest groups, multi-language.", //, synchronization
        "version" => "3.1",
        "author" => "SupportMonk - www.supportmonk.com",
        "language" => "english",
        "fields" => [
            "apikey" => [ "FriendlyName" => "MailChimp API Key", "Type" => "text", "Size" => "100", "Description" => "Enter your MailChimp API key." ],
            "baseurl" => [ "FriendlyName" => "WHMCS Base URL", "Type" => "text", "Size" => "100", "Description" => "Enter the base URL of your WHMCS. Eg: http://yourcompany.com/whmcs" ],
            "delete" => [ "FriendlyName" => "Delete newsletter fields on deactivation", "Type" => "yesno", "Description" => "Delete table and newsletter custom fields added by this module on deactivation. If enabled, settings and newsletter subscription statuses will be lost when you activate this module again." ],
            "debug" => [ "FriendlyName" => "Debug level", "Type" => "dropdown", "Options" => "0,1,2,3,4,5", "Description" => "Lot of debugging messages will be logged in Activity Logs", "Default" => "0" ],
        ]
    ];
    return $config;
}

function module_language_init() {
    $defaultLang = 'english';
    /* Get WHMCS default language */
    try {
        $rows = Capsule::table('tblconfiguration')
            ->select('value')
            ->where('setting', 'Language')
            ->get();
        if ($rows[0]) {
            $defaultLang = $rows[0]->value;
        }
    } catch (\Exception $e) {
        logActivity("module_language_init: ERROR: get language setting: " . $e->getMessage());
    }
    $_ADDONLANG = [];
    $langFile = dirname(__FILE__) . '/lang/' . strtolower($defaultLang) . '.php';
    if (file_exists($langFile)) {
        include($langFile);
    } else {
        logActivity("module_language_init: $langFile ($defaultLang) not found!");
        $langFile = dirname(__FILE__) . '/lang/' . $defaultLang . '.php';
        if (file_exists($langFile)) {
            include($langFile);
        } else {
            logActivity("module_language_init: $langFile ($defaultLang) not found! Fall back to English.");
            include(dirname(__FILE__) . '/lang/english.php');
        }
    }
    return $_ADDONLANG;
}

function _drop_ezchimp_table() {
    try {
        Capsule::schema()->drop('mod_ezchimp');
    } catch (\Exception $e) {
        logActivity("_drop_ezchimp_table: ERROR: " . $e->getMessage());
    }
}

function ezchimp_activate() {
    $ezconf = new EzchimpConf();
    $LANG = module_language_init();

    # Create table and custom client fields for newsletter
    if (! Capsule::schema()->hasTable('mod_ezchimp')) {
        try {
            Capsule::schema()->create('mod_ezchimp', function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->string('setting', 30);
                $table->text('value')->default('');
                $table->primary('setting');
            });
        } catch (\Exception $e) {
            logActivity("ezchimp_activate: ERROR: create table: " . $e->getMessage());
            return ['status' => 'error', 'description' => 'Could not create table: ' . $e->getMessage()];
        }
        /* Default settings */
        $settings = [
            ['setting' => 'format_select', 'value' => 'on'],
            ['setting' => 'interest_select', 'value' => 'on'],
            ['setting' => 'subscribe_contacts', 'value' => 'on'],
            ['setting' => 'showorder', 'value' => 'on'],
            ['setting' => 'delete_member', 'value' => ''],
            ['setting' => 'default_subscribe', 'value' => ''],
            ['setting' => 'default_format', 'value' => 'html'],
            ['setting' => 'default_subscribe_contact', 'value' => ''],
            ['setting' => 'activelists', 'value' => ''],
            ['setting' => 'affiliatelists', 'value' => ''],
            ['setting' => 'listnames', 'value' => ''],
            ['setting' => 'groupings', 'value' => ''],
            ['setting' => 'unsubscribe_groupings', 'value' => ''],
            ['setting' => 'interestmaps', 'value' => '']
        ];
        if ($ezconf->debug > 1) {
            logActivity("ezchimp_activate: module settings init - " . print_r($settings, true));
        }
        try {
            Capsule::table('mod_ezchimp')->insert($settings);
        } catch (\Exception $e) {
            logActivity("ezchimp_activate: ERROR: module settings init: " . $e->getMessage());
            _drop_ezchimp_table();
            return ['status' => 'error', 'description' => 'Could not add default settings: ' . $e->getMessage()];
        }
    } else {
        logActivity("ezchimp_activate: mod_ezchimp table already exists");
    }
    /* Email format custom field */
    try {
        $count = Capsule::table('tblcustomfields')
            ->where([
                [ 'type', '=', 'client' ],
                [ 'fieldtype', '=', 'dropdown' ],
                [ 'sortorder', '=', 46307 ],
            ])
            ->count();
    } catch (\Exception $e) {
        logActivity("ezchimp_activate: ERROR: check if email format custom field exists: " . $e->getMessage());
        _drop_ezchimp_table();
        return [ 'status' => 'error', 'description' => 'Could not check email format custom field: ' . $e->getMessage() ];
    }
	if ($count > 0) {
        try {
            Capsule::table('tblcustomfields')
                ->where([
                    [ 'type', '=', 'client' ],
                    [ 'fieldtype', '=', 'dropdown' ],
                    [ 'sortorder', '=', 46307 ]
                ])
                ->update([
                    'fieldname' => $LANG['email_format'],
                    'adminonly' => '',
                    'showorder' => 'on'
                ]);
        } catch (\Exception $e) {
            logActivity("ezchimp_activate: ERROR: update email format custom field 1: " . $e->getMessage());
            _drop_ezchimp_table();
            return [ 'status' => 'error', 'description' => 'Could not update email format custom field: ' . $e->getMessage() ];
        }
	} else {
        try {
            Capsule::table('tblcustomfields')
                ->insert([
                    'type' => 'client',
                    'relid' => 0,
                    'fieldname' => $LANG['email_format'],
                    'fieldtype' => 'dropdown',
                    'description' => $LANG['email_format_desc'],
                    'fieldoptions' => 'html,text',
                    'regexpr' => '',
                    'adminonly' => '',
                    'required' => '',
                    'showorder' => 'on',
                    'showinvoice' => '',
                    'sortorder' => 46307
                ]);
        } catch (\Exception $e) {
            logActivity("ezchimp_activate: ERROR: insert email format custom field: " . $e->getMessage());
            _drop_ezchimp_table();
            return [ 'status' => 'error', 'description' => 'Could not insert email format custom field: ' . $e->getMessage() ];
        }
	}
    /* Subscribe all contacts custom field */
    try {
        $count = Capsule::table('tblcustomfields')
            ->where([
                [ 'type', '=', 'client' ],
                [ 'fieldtype', '=', 'tickbox' ],
                [ 'sortorder', '=', 46309 ],
            ])
            ->count();
    } catch (\Exception $e) {
        logActivity("ezchimp_activate: ERROR: check if subscribe all contacts custom field exists: " . $e->getMessage());
        _drop_ezchimp_table();
        return [ 'status' => 'error', 'description' => 'Could not check subscribe all contacts custom field: ' . $e->getMessage() ];
    }
    if ($count > 0) {
        try {
            Capsule::table('tblcustomfields')
                ->where([
                    [ 'type', '=', 'client' ],
                    [ 'fieldtype', '=', 'tickbox' ],
                    [ 'sortorder', '=', 46309 ]
                ])
                ->update([
                    'fieldname' => $LANG['subscribe_all_contacts'],
                    'adminonly' => '',
                    'showorder' => 'on'
                ]);
        } catch (\Exception $e) {
            logActivity("ezchimp_activate: ERROR: update subscribe all contacts custom field 1: " . $e->getMessage());
            _drop_ezchimp_table();
            return [ 'status' => 'error', 'description' => 'Could not update subscribe all contacts custom field: ' . $e->getMessage() ];
        }
    } else {
        try {
            Capsule::table('tblcustomfields')
                ->insert([
                    'type' => 'client',
                    'relid' => 0,
                    'fieldname' => $LANG['subscribe_all_contacts'],
                    'fieldtype' => 'tickbox',
                    'description' => $LANG['subscribe_all_contacts_desc'],
                    'fieldoptions' => '',
                    'regexpr' => '',
                    'adminonly' => '',
                    'required' => '',
                    'showorder' => 'on',
                    'showinvoice' => '',
                    'sortorder' => 46309
                ]);
        } catch (\Exception $e) {
            logActivity("ezchimp_activate: ERROR: insert subscribe all contacts custom field: " . $e->getMessage());
            _drop_ezchimp_table();
            return [ 'status' => 'error', 'description' => 'Could not insert subscribe all contacts custom field: ' . $e->getMessage() ];
        }
    }

    return [ 'status'=>'success','description'=>'ezchimp - Mailchimp Newsletter add-on module activated' ];
}

function ezchimp_deactivate() {
    $ezconf = new EzchimpConf();

	/* Module vars */
	$config = _ezchimp_config($ezconf);
	if ($ezconf->debug > 1) {
		logActivity("ezchimp_deactivate: module config - ".print_r($config, true));
	}

	if (isset($config['delete']) && ('on' == $config['delete'])) {
		# Remove table, custom client fields and custom fields
        _drop_ezchimp_table();
        /* Email format custom field */
        $emailFormatFieldId = 0;
        try {
            $rows = Capsule::table('tblcustomfields')
                ->select('id')
                ->where([
                    [ 'type', '=', 'client' ],
                    [ 'fieldtype', '=', 'dropdown' ],
                    [ 'sortorder', '=', 46307 ]
                ])
                ->get();
            if ($rows[0]) {
                $emailFormatFieldId = $rows[0]->id;
            }
        } catch (\Exception $e) {
            logActivity("ezchimp_deactivate: ERROR: get email format custom field ID: " . $e->getMessage());
        }
		if ($ezconf->debug > 1) {
			logActivity("ezchimp_deactivate: emailformat_fieldid - $emailFormatFieldId");
		}
		if ($emailFormatFieldId > 0) {
            try {
                Capsule::table('tblcustomfieldsvalues')
                    ->where([
                        [ 'fieldid', '=', $emailFormatFieldId ]
                    ])
                    ->delete();
            } catch (\Exception $e) {
                logActivity("ezchimp_deactivate: ERROR: delete email format custom field values: " . $e->getMessage());
            }
		}
        try {
            Capsule::table('tblcustomfields')
                ->where([
                    [ 'id', '=', $emailFormatFieldId ]
                ])
                ->delete();
        } catch (\Exception $e) {
            logActivity("ezchimp_deactivate: ERROR: delete email format custom field: " . $e->getMessage());
        }
        /* Subscribe contacts custom field */
        $subscribeContactsFieldId = 0;
        try {
            $rows = Capsule::table('tblcustomfields')
                ->select('id')
                ->where([
                    [ 'type', '=', 'client' ],
                    [ 'fieldtype', '=', 'tickbox' ],
                    [ 'sortorder', '=', 46309 ]
                ])
                ->get();
            if ($rows[0]) {
                $subscribeContactsFieldId = $rows[0]->id;
            }
        } catch (\Exception $e) {
            logActivity("ezchimp_deactivate: ERROR: get subscribe contacts custom field ID: " . $e->getMessage());
        }
        if ($ezconf->debug > 1) {
            logActivity("ezchimp_deactivate: subscribe_contacts_fieldid - $subscribeContactsFieldId");
        }
        if ($subscribeContactsFieldId > 0) {
            try {
                Capsule::table('tblcustomfieldsvalues')
                    ->where([
                        [ 'fieldid', '=', $subscribeContactsFieldId ]
                    ])
                    ->delete();
            } catch (\Exception $e) {
                logActivity("ezchimp_deactivate: ERROR: delete subscribe contacts custom field values: " . $e->getMessage());
            }
        }
        try {
            Capsule::table('tblcustomfields')
                ->where([
                    [ 'id', '=', $subscribeContactsFieldId ]
                ])
                ->delete();
        } catch (\Exception $e) {
            logActivity("ezchimp_deactivate: ERROR: delete subscribe contacts custom field: " . $e->getMessage());
        }
        /* Newsletter custom fields */
        $fieldIds = [];
        try {
            $rows = Capsule::table('tblcustomfields')
                ->select('id')
                ->where([
                    [ 'type', '=', 'client' ],
                    [ 'fieldtype', '=', 'tickbox' ],
                    [ 'sortorder', '=', 46306 ]
                ])
                ->get();
            foreach ($rows as $row) {
                $fieldIds[] = $row->id;
            }
        } catch (\Exception $e) {
            logActivity("ezchimp_deactivate: ERROR: get newsletters custom field IDs: " . $e->getMessage());
        }
        if ($ezconf->debug > 1) {
            logActivity("ezchimp_deactivate: newsletter custom field IDs - " . implode(',', $fieldIds));
        }
        if (!empty($fieldIds)) {
            try {
                Capsule::table('tblcustomfieldsvalues')
                    ->whereIn('fieldid', $fieldIds)
                    ->delete();
            } catch (\Exception $e) {
                logActivity("ezchimp_deactivate: ERROR: delete newsletter custom fields values: " . $e->getMessage());
            }
        }
        try {
            Capsule::table('tblcustomfields')
                ->whereIn('id', $fieldIds)
                ->delete();
        } catch (\Exception $e) {
            logActivity("ezchimp_deactivate: ERROR: delete newsletter custom fields: " . $e->getMessage());
        }
	} else {
        try {
            Capsule::table('tblcustomfields')
                ->where([
                    [ 'type', '=', 'client' ],
                    [ 'fieldtype', '=', 'dropdown' ],
                    [ 'sortorder', '=', 46307 ]
                ])
                ->update([
                    'adminonly' => 'on',
                    'showorder' => ''
                ]);
        } catch (\Exception $e) {
            logActivity("ezchimp_deactivate: ERROR: update email format custom field 2: " . $e->getMessage());
        }
        try {
            Capsule::table('tblcustomfields')
                ->where([
                    [ 'type', '=', 'client' ],
                    [ 'fieldtype', '=', 'tickbox' ],
                    [ 'sortorder', '=', 46309 ]
                ])
                ->update([
                    'adminonly' => 'on',
                    'showorder' => ''
                ]);
        } catch (\Exception $e) {
            logActivity("ezchimp_deactivate: ERROR: update subscribe all contacts custom field 2: " . $e->getMessage());
        }
        try {
            Capsule::table('tblcustomfields')
                ->where([
                    [ 'type', '=', 'client' ],
                    [ 'fieldtype', '=', 'tickbox' ],
                    [ 'sortorder', '=', 46306 ]
                ])
                ->update([
                    'adminonly' => 'on',
                    'showorder' => ''
                ]);
        } catch (\Exception $e) {
            logActivity("ezchimp_deactivate: ERROR: update newsletter custom fields: " . $e->getMessage());
        }
	}

    # Return Result
    return [ 'status' => 'success', 'description' => 'Thank you for using ezchimp. - www.supportmonk.com' ];

}

function ezchimp_upgrade($vars) {
    $version = $vars['version'];
}

function ezchimp_output($vars) {
    $ezconf = new EzchimpConf();

    $modulelink = $vars['modulelink'];
    $version = $vars['version'];
    $apikey = $vars['apikey'];
    // $LANG = $vars['_lang'];
    $LANG = module_language_init();

    if (isset($vars['debug'])) {
    	$ezconf->debug = intval($vars['debug']);
    	if ($ezconf->debug > 0) {
    		logActivity("ezchimp_output: Debug enabled - ".$ezconf->debug);
    	}
    }

    /* Get WHMCS version */
    $whmcs_version = 'Unknown';
    try {
        $rows = Capsule::table('tblconfiguration')
            ->select('value')
            ->where('setting', 'Version')
            ->get();
        if ($rows[0]) {
            $whmcs_version = $rows[0]->value;
        }
    } catch (\Exception $e) {
        logActivity("ezchimp_output: ERROR: get WHMCS version: " . $e->getMessage());
    }

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
            <li><a href="'.$modulelink.'&page=affiliatelists">'.$LANG['affiliate_lists'].'</a></li>
        </ul></div>';
//    }

    echo '<p>ezchimp '.$version.' [WHMCS '.$whmcs_version.']</p>';
    echo '<p>'.$LANG['intro'].'</p>
    <p>'.$LANG['description'].'</p>
    <p>'.$LANG['documentation'].' <a href="http://blog.supportmonk.com/whmcs-addon-modules/ezchimp-whmcs-mailchimp-integration" target="_blank">'.$LANG['here'].'</a></p>
    <p>Copyright &copy; SupportMonk - <a href="http://www.supportmonk.com/" target="_blank">www.supportmonk.com</a></p>';

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
                if (!isset($search_field) || !in_array($search_field, [ 'email', 'firstname', 'lastname' ])) {
                    $search_field = 'email';
                }
                $email_selected = $firstname_selected = $lastname_selected = '';
                ${$search_field.'_selected'} = ' selected="selected"';

                /* Find total no. or rows */
                $query_params = [];
                if ('on' == $settings['subscribe_contacts']) {
                    $query_from = "FROM `tblclients` AS `cl` LEFT JOIN `tblcontacts` AS `ct` ON `cl`.`id` = `ct`.`userid`";
                } else {
                    $query_from = "FROM `tblclients` AS `cl`";
                }
                if ('' != $search_text) {
                    if ('on' == $settings['subscribe_contacts']) {
                        $query_from .= " WHERE `cl`.`$search_field` LIKE :search_text1 OR `ct`.`$search_field` LIKE :search_text";
                        $query_params[':search_text1'] = '%' . $search_text . '%';
                    } else {
                        $query_from .= " WHERE `cl`.`$search_field` LIKE :search_text";
                    }
                    $query_params[':search_text'] = '%' . $search_text . '%';
                }
                $query = "SELECT count(*) AS `total` $query_from";
                if ($ezconf->debug > 4) {
                    logActivity("ezchimp_output: count query, params - $query, ".print_r($query_params, true));
                }
                $total = 0;
                $pdo = Capsule::connection()->getPdo();
                try {
                    $statement = $pdo->prepare($query);
                    if ($statement->execute($query_params)) {
                        $row = $statement->fetch(PDO::FETCH_ASSOC);
                        $total = $row['total'];
                    } else {
                        logActivity("ezchimp_output: count query failed");
                    }
                } catch (PDOException $e) {
                    logActivity("ezchimp_output: count query exception: " . $e->getMessage());
                }
                $statement->closeCursor();

                $pages = ceil($total / EMAILS_LIMIT);
	    		if ($ezconf->debug > 3) {
	    			logActivity("ezchimp_output: status - page_number, total, pages : $page_number, $total, $pages");
	    		}

	    		echo '<br /><h2>'.$LANG['status'].'</h2><p>'.$LANG['status_desc'].'</p><p>';
                /* Search form */
                echo '<form action="'.$modulelink.'&page=status" name="SearchForm" method="POST">
                        <input type="text" name="searchtext" value="'.htmlspecialchars($search_text).'" />
                            <select name="seachfield">
                                <option value="email"'.$email_selected.'>'.$LANG['email'].'</option>
                                <option value="firstname"'.$firstname_selected.'>'.$LANG['firstname'].'</option>
                                <option value="lastname"'.$lastname_selected.'>'.$LANG['lastname'].'</option>
                            </select>
                        <input type="submit" name="search" value="'.$LANG['search'].'" />
                    </form>';

                /* Pagination links */
                if ($pages > 1) {
                    echo '<ul class="pager">';
                    if ($page_number > 1) {
                        echo '<li class="previous"><a href="'.$modulelink.'&page=status&p='.($page_number - 1).'">'.$LANG['prev'].'</a> </li>';
                    }
                    if ($page_number < $pages) {
                        echo '<li class="next"><a href="'.$modulelink.'&page=status&p='.($page_number + 1).'">'.$LANG['next'].'</a> </li>';
                    }
                    echo '&nbsp; ';
                    for ($i = 1; $i <= $pages; $i++) {
                        if ($i == $page_number) {
                            $pg = "<b>$i</b>";
                        } else {
                            $pg = $i;
                        }
                        echo '<li><a href="'.$modulelink.'&page=status&p='.$i.'">'.$pg.'</a> </li>';
                    }
                    echo '<ul>';
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
    				logActivity("ezchimp_output: activelists 1 - ".print_r($activelists, true));
    				logActivity("ezchimp_output: listnames 1 - ".print_r($listnames, true));
    			}

    			$emails = [];
                $offset = ($page_number - 1) * EMAILS_LIMIT;
                if ('on' == $settings['subscribe_contacts']) {
                    $query = "SELECT `cl`.`id` AS `client_id`, `cl`.`firstname` AS `client_firstname`, `cl`.`lastname` AS `client_lastname`, `cl`.`email` AS `client_email`, `ct`.`id` AS `contact_id`, `ct`.`firstname` AS `contact_firstname`, `ct`.`lastname` AS `contact_lastname`, `ct`.`email` AS `contact_email` $query_from ORDER BY `client_id` ASC, `contact_id` ASC LIMIT $offset, " . EMAILS_LIMIT;
                } else {
                    $query = "SELECT `cl`.`id` AS `client_id`, `cl`.`firstname` AS `client_firstname`, `cl`.`lastname` AS `client_lastname`, `cl`.`email` AS `client_email` $query_from ORDER BY `client_id` ASC LIMIT $offset, " . EMAILS_LIMIT;
                }
	    		if ($ezconf->debug > 4) {
	    			logActivity("ezchimp_output: query - $query");
	    		}
                try {
                    $statement2 = $pdo->prepare($query);
                    if ($statement2->execute($query_params)) {
                        while ($row = $statement2->fetch(PDO::FETCH_ASSOC)) {
                            $clientId = $row['client_id'];
                            if (!isset($emails[$row['client_email']]) && (('' == $search_text) || (stripos($row['client_'.$search_field], $search_text) !== false))) {
                                $emails[$row['client_email']]['clientid'] = $clientId;
                                $emails[$row['client_email']]['firstname'] = $row['client_firstname'];
                                $emails[$row['client_email']]['lastname'] = $row['client_lastname'];
                            }
                            if (!empty($row['contact_id']) && (('' == $search_text) || (stripos($row['contact_'.$search_field], $search_text) !== false))) {
                                $emails[$row['contact_email']]['clientid'] = $clientId;
                                $emails[$row['contact_email']]['contactid'] = $row['contact_id'];
                                $emails[$row['contact_email']]['firstname'] = $row['contact_firstname'];
                                $emails[$row['contact_email']]['lastname'] = $row['contact_lastname'];
                            }
                        }
                    } else {
                        logActivity("ezchimp_output: select query failed");
                    }
                    $statement2->closeCursor();
                } catch (PDOException $e) {
                    logActivity("ezchimp_output: select query exception: " . $e->getMessage());
                }
	    		if ($ezconf->debug > 4) {
	    			logActivity("ezchimp_output: emails - ".print_r($emails, true));
	    		}
	    		$email_statuses = [];
                $email_addresses = array_keys($emails);

                foreach ($activelists as $listid => $groups) {
                    $params = [ 'apikey' => $apikey, 'id' => $listid, 'email_addresses' => $email_addresses ];
                    $members = _ezchimp_listMemberInfo($params, $ezvars);
                    if ($ezconf->debug > 4) {
                        logActivity("ezchimp_output: members - " . print_r($members, true));
                    }
                    $i = 0;
                    if (!empty($members)) {
                        foreach ($members as $entry) {
                            if (isset($entry->email_address)) {
                                if ("subscribed" === $entry->status) {
                                    $email = $entry->email_address;
                                    if (!isset($email_statuses[$email])) {
                                        $email_statuses[$email] = $emails[$email];
                                    }
                                    $email_statuses[$email]['subscriptions'][$listid] = [];
                                    $email_statuses[$email]['subscriptions'][$listid]['format'] = isset($entry->email_type) ? $entry->email_type : 'NA';
                                    $email_statuses[$email]['subscriptions'][$listid]['status'] = isset($entry->status) ? $entry->status : 'NA';
                                    $email_statuses[$email]['subscriptions'][$listid]['rating'] = isset($entry->member_rating) ? $entry->member_rating . ' / 5' : 'NA';
                                    $groups_str = '';
                                    $interestmaps = unserialize($ezvars->settings['interestmaps']);
                                    $interestmap = $interestmaps[$listid];
                                    if ($ezvars->debug > 3) {
                                        logActivity("ezchimp_output: interestmap:" . print_r($interestmap, 1));
                                    }
                                    if (!empty($entry->interests)) {
                                        $interests = get_object_vars($entry->interests);
                                        if ($ezvars->debug > 3) {
                                            logActivity("ezchimp_output: interests:" . print_r($interests, 1));
                                        }
                                        foreach ($interests as $interest_id => $subscribed) {
                                            if ($subscribed) {
                                                foreach ($interestmap as $key => $id) {
                                                    if ($id === $interest_id) {
                                                        $groups_str .= str_replace('^:', ' > ', $key) . '<br />';
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    $email_statuses[$email]['subscriptions'][$listid]['groups'] = $groups_str;
                                } else if ($debug > 1) {
                                    logActivity("ezchimp_output: status ($listid) - Unsubscribed email ($i) - " . $entry->email_address);
                                }
                            } else {
                                logActivity("ezchimp_output: status ($listid) - Invalid MemberInfo entry ($i) - " . print_r($entry, 1));
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
                $clid = $ctid = [];
                foreach ($email_statuses as $email => $info) {
                    $clid[$email] = $info['clientid'];
                    $ctid[$email] = isset($info['contactid']) ? $info['contactid'] : 0;
                }
                array_multisort($clid, SORT_ASC, $ctid, SORT_ASC, $email_statuses);
                if ($ezconf->debug > 4) {
                    logActivity("ezchimp_output: sorted email_statuses - ".print_r($email_statuses, true));
                }
                $alter = 0;

	    		foreach ($email_statuses as $email => $info) {
                    if (empty($info['subscriptions'])) {
                        $alter++;
                        if ($alter % 2 == 0) {
                            echo '<tr class="rowhighlight">';
                        } else {
                            echo '<tr>';
                        }
                        echo '
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
                            $alter++;
                            if ($alter % 2 == 0) {
                                echo '<tr class="rowhighlight">';
                            } else {
                                echo '<tr>';
                            }
                            echo '
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
		<td>'.$subscription['groups'].'</td>';
              if ('pending' == $subscription['status']) {
                  echo '<td class="textorange">' . $subscription['status'] . '</td>';
              } else if ('subscribed' == $subscription['status']) {
                  echo '<td class="textgreen">' . $subscription['status'] . '</td>';
              } else {
                  echo '<td>' . $subscription['status'] . '</td>';
              }
		echo '<td>'.$subscription['rating'].'</td>
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
                        $optin_only = false;
                        $hosting_active = false;
                        $include_inactive = false;
                        if ('subscribe_empty_optin' == $action) {
                            $action = 'subscribe_empty';
                            $optin_only = true;
                        } else if ('subscribe_empty_all_optin' == $action) {
                            $action = 'subscribe_empty';
                            $optin_only = true;
                            $include_inactive = true;
                        } else if ('subscribe_empty_hosting_active' == $action) {
                            $action = 'subscribe_empty';
                            $hosting_active = true;
                        } else if ('subscribe_empty_hosting_active_optin' == $action) {
                            $action = 'subscribe_empty';
                            $optin_only = true;
                            $hosting_active = true;
                        }
		    			switch ($action) {
		    				case 'subscribe_empty':
								$fieldIds = [];
                                try {
                                    $rows = Capsule::table('tblcustomfields')
                                        ->select('id')
                                        ->where([
                                            [ 'type', '=', 'client' ],
                                            [ 'fieldtype', '=', 'tickbox' ],
                                            [ 'sortorder', '=', 46306 ]
                                        ])
                                        ->get();
                                    foreach ($rows as $row) {
                                        $fieldIds[] = $row->id;
                                    }
                                    if ($ezconf->debug > 2) {
                                        logActivity("ezchimp_output: fieldids - " . implode(',', $fieldIds));
                                    }
                                } catch (\Exception $e) {
                                    logActivity("ezchimp_output: ERROR: get newsletters custom field IDs: " . $e->getMessage());
                                }

                                if (!empty($fieldIds)) {
                                    $subscribe_contacts_fieldid = 0;
                                    try {
                                        $rows = Capsule::table('tblcustomfields')
                                            ->select('id')
                                            ->where([
                                                [ 'type', '=', 'client' ],
                                                [ 'fieldtype', '=', 'tickbox' ],
                                                [ 'sortorder', '=', 46309 ]
                                            ])
                                            ->get();
                                        if ($rows[0]) {
                                            $subscribe_contacts_fieldid = $rows[0]->id;
                                        }
                                    } catch (\Exception $e) {
                                        logActivity("ezchimp_output: ERROR: get subscribe contacts custom field ID: " . $e->getMessage());
                                    }

                                    $default_format_fieldid = 0;
                                    try {
                                        $rows = Capsule::table('tblcustomfields')
                                            ->select('id')
                                            ->where([
                                                [ 'type', '=', 'client' ],
                                                [ 'fieldtype', '=', 'dropdown' ],
                                                [ 'sortorder', '=', 46307 ]
                                            ])
                                            ->get();
                                        if ($rows[0]) {
                                            $default_format_fieldid = $rows[0]->id;
                                        }
                                    } catch (\Exception $e) {
                                        logActivity("ezchimp_output: ERROR: get default email format custom field ID: " . $e->getMessage());
                                    }

                                    $subscribed = [];
                                    try {
                                        $rows = Capsule::table('tblcustomfieldsvalues')
                                            ->select('relid')
                                            ->whereIn('fieldid', $fieldIds)
                                            ->get();
                                        foreach ($rows as $row) {
                                            $subscribed[$row->relid] = 1;
                                        }
                                        if ($ezconf->debug > 2) {
                                            logActivity("ezchimp_output: subscribed - ".print_r($subscribed, true));
                                        }
                                    } catch (\Exception $e) {
                                        logActivity("ezchimp_output: ERROR: get subscriptions: " . $e->getMessage());
                                    }

									$activelists = unserialize($settings['activelists']);
									if ($ezconf->debug > 2) {
										logActivity("ezchimp_output: activelists 2 - ".print_r($activelists, true));
									}

									$emailType = $settings['default_format'];
									$subscriptions = [];
									foreach ($activelists as $list => $groups) {
										if (!is_array($groups)) {
											$subscriptions[] = [ 'list' => $list ];
										} else {
											$subscription_groupings = [];
											foreach ($groups as $mainGroup => $subgroups) {
                                                $subscription_groupings[] = [ 'name' => $mainGroup, 'groups' => array_keys($subgroups) ];
											}
											if (!empty($subscription_groupings)) {
												$subscriptions[] = [ 'list' => $list, 'grouping' => $subscription_groupings ];
											}
										}
									}
					    			$clients = [];
                                    $rows = [];
                                    if ($hosting_active) {
                                        $where = [
                                            [ 'tblhosting.domainstatus', '=', 'Active' ],
                                            [ 'tblclients.status', '=', 'Active' ]
                                        ];
                                        if ($optin_only) {
                                            array_push($where, [ 'tblclients.emailoptout', '=', 0 ]);
                                        }
                                        try {
                                            $rows = Capsule::table('tblclients')
                                                ->join('tblhosting', 'tblclients.id', '=', 'tblhosting.userid')
                                                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                                                ->select('tblclients.id as id', 'tblclients.firstname as firstname', 'tblclients.lastname as lastname', 'tblclients.email as email')
                                                ->distinct()
                                                ->where($where)
                                                ->get();
                                        } catch (\Exception $e) {
                                            logActivity("ezchimp_output: ERROR: get active hosting clients: " . $e->getMessage());
                                        }
                                    } else {
                                        $where = [];
                                        if (!$include_inactive) {
                                            array_push($where, [ 'status', '=', 'Active' ]);
                                        }
                                        if ($optin_only) {
                                            array_push($where, [ 'emailoptout', '=', 0 ]);
                                        }
                                        try {
                                            $rows = Capsule::table('tblclients')
                                                ->select('id', 'firstname', 'lastname', 'email')
                                                ->where($where)
                                                ->get();
                                        } catch (\Exception $e) {
                                            logActivity("ezchimp_output: ERROR: get active clients 1: " . $e->getMessage());
                                        }
                                    }
                                    if (!empty($rows)) {
                                        foreach ($rows as $row) {
                                            $clientId = $row->id;
                                            $firstName = $row->firstname;
                                            $lastName = $row->lastname;
                                            $email = $row->email;

                                            if ($default_format_fieldid > 0) {
                                                /* Set default format for client */
                                                try {
                                                    Capsule::table('tblcustomfieldsvalues')
                                                        ->insert([
                                                            'fieldid' => $default_format_fieldid,
                                                            'relid' => $clientId,
                                                            'value' => 'on'
                                                        ]);
                                                } catch (\Exception $e) {
                                                    logActivity("ezchimp_output: ERROR: insert default email format for client: " . $e->getMessage());
                                                }
                                            }

                                            if (!isset($subscribed[$clientId])) {
                                                $clients[$clientId]['self'] = $row;
                                                /* Update database */
                                                foreach ($fieldIds as $fieldId) {
                                                    try {
                                                        Capsule::table('tblcustomfieldsvalues')
                                                            ->insert([
                                                                'fieldid' => $fieldId,
                                                                'relid' => $clientId,
                                                                'value' => 'on'
                                                            ]);
                                                    } catch (\Exception $e) {
                                                        logActivity("ezchimp_output: ERROR: insert subscription for client: " . $e->getMessage());
                                                    }
                                                }

                                                /* Update MailChimp */
                                                foreach ($subscriptions as $subscription) {
                                                    _ezchimp_subscribe($subscription, $firstName, $lastName, $email, $emailType, $ezvars);
                                                }
                                                if ($ezconf->debug > 2) {
                                                    logActivity("ezchimp_output: subscribed client - $firstName $lastName <$email>");
                                                }
                                                if ('on' == $settings['subscribe_contacts']) {
                                                    if ($subscribe_contacts_fieldid > 0) {
                                                        /* Set subscribe contacts for client */
                                                        try {
                                                            Capsule::table('tblcustomfieldsvalues')
                                                                ->insert([
                                                                    'fieldid' => $subscribe_contacts_fieldid,
                                                                    'relid' => $clientId,
                                                                    'value' => 'on'
                                                                ]);
                                                        } catch (\Exception $e) {
                                                            logActivity("ezchimp_output: ERROR: insert subscribe contacts for client: " . $e->getMessage());
                                                        }
                                                    }

                                                    $contacts = [];
                                                    try {
                                                        $contacts = Capsule::table('tblcontacts')
                                                            ->select('id', 'firstname', 'lastname', 'email')
                                                            ->where([
                                                                [ 'userid', '=', $clientId ]
                                                            ])
                                                            ->get();
                                                    } catch (\Exception $e) {
                                                        logActivity("ezchimp_output: ERROR: get client contacts: " . $e->getMessage());
                                                    }
                                                    if (!empty($contacts)) {
                                                        foreach ($contacts as $contact) {
                                                            $contactId = $contact->id;
                                                            $firstName = $contact->firstname;
                                                            $lastName = $contact->lastname;
                                                            $email = $contact->email;
                                                            $clients[$clientId]['contacts'][$contactId] = $contact;
                                                            foreach ($subscriptions as $subscription) {
                                                                _ezchimp_subscribe($subscription, $firstName, $lastName, $email, $emailType, $ezvars);
                                                            }
                                                            if ($ezconf->debug > 2) {
                                                                logActivity("ezchimp_output: subscribed contact - $firstName $lastName <$email>");
                                                            }
                                                        }
                                                    }
                                                }
                                            } else {
                                                if ($ezconf->debug > 1) {
                                                    logActivity("ezchimp_output: already subscribed - $firstName $lastName <$email>");
                                                }
                                            }
                                        }
                                    }

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
		<td><a href="clientssummary.php?userid='.$id.'">'.$details['self']->firstname.'</a></td>
		<td><a href="clientssummary.php?userid='.$id.'">'.$details['self']->lastname.'</a></td>
		<td><a href="mailto:'.$details['self']->email.'">'.$details['self']->email.'</a></td>
	</tr>';
					    					if (!empty($details['contacts'])) {
					    						foreach ($details['contacts'] as $ctid => $contact) {
					    							echo '
	<tr>
		<td><a href="clientssummary.php?userid='.$id.'">'.$id.'</a></td>
		<td><a href="clientscontacts.php?userid='.$id.'&contactid='.$ctid.'">'.$ctid.'</a></td>
		<td><a href="clientscontacts.php?userid='.$id.'&contactid='.$ctid.'">'.$contact->firstname.'</a></td>
		<td><a href="clientscontacts.php?userid='.$id.'&contactid='.$ctid.'">'.$contact->lastname.'</a></td>
		<td><a href="mailto:'.$contact->email.'">'.$contact->email.'</a></td>
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
					    				logActivity("ezchimp_output: $action - No active lists/groups 1");
					    			}
					    			echo '<div class="errorbox"><strong>'.$LANG['no_active_lists'].'</strong></div>';
								}
		    					break;

                            case 'reset_to_autosubscribe':
                                $fieldIds = [];
                                try {
                                    $rows = Capsule::table('tblcustomfields')
                                        ->select('id')
                                        ->where([
                                            [ 'type', '=', 'client' ],
                                            [ 'fieldtype', '=', 'tickbox' ],
                                            [ 'sortorder', '=', 46306 ]
                                        ])
                                        ->get();
                                    foreach ($rows as $row) {
                                        $fieldIds[] = $row->id;
                                    }
                                } catch (\Exception $e) {
                                    logActivity("reset_to_autosubscribe: ERROR: get custom field IDs: " . $e->getMessage());
                                }

                                if (!empty($fieldIds)) {
                                    $errors = [];

                                    $groupings = unserialize($settings['groupings']);
                                    if ($ezconf->debug > 0) {
                                        logActivity("reset_to_autosubscribe: groupings - " . print_r($groupings, true));
                                    }

                                    $clientCount = 0;
                                    try {
                                        $clientCount = Capsule::table('tblclients')
                                            ->select('id')
                                            ->where([
                                                [ 'status', '=', 'Active' ]
                                            ])
                                            ->count();
                                    } catch (\Exception $e) {
                                        logActivity("reset_to_autosubscribe: ERROR: get client count: " . $e->getMessage());
                                    }
                                    if ($clientCount) {
                                        $offset = 0;
                                        $limit = 100;
                                        do {
                                            if ($ezconf->debug > 1) {
                                                logActivity("reset_to_autosubscribe: offset: $offset, limit: $limit, total: $clientCount");
                                            }
                                            $clients = [];
                                            try {
                                                $clients = Capsule::table('tblclients')
                                                    ->select('id', 'firstname', 'lastname', 'email')
                                                    ->where([
                                                        [ 'status', '=', 'Active' ]
                                                    ])
                                                    ->orderBy('id', 'asc')
                                                    ->skip($offset)
                                                    ->take($limit)
                                                    ->get();
                                            } catch (\Exception $e) {
                                                logActivity("reset_to_autosubscribe: ERROR: get active clients: " . $e->getMessage());
                                            }
                                            if (!empty($clients)) {
                                                foreach ($clients as $client) {
                                                    $clientId = $client->id;
                                                    $firstName = $client->firstname;
                                                    $lastName = $client->lastname;
                                                    $email = $client->email;
                                                    $emailType = _ezchimp_client_email_type($clientId, $ezvars);

                                                    $productGroupNames = [];
                                                    /* Check ordered domains if domains grouping available */
                                                    if (!empty($groupings['Domains']) || !empty($groupings1['Domains'])) {
                                                        try {
                                                            $domain_count = Capsule::table('tbldomains')
                                                                ->select('id')
                                                                ->where([
                                                                    [ 'userid', '=', $clientId ]
                                                                ])
                                                                ->count();
                                                            if ($domain_count > 0) {
                                                                $productGroupNames['Domains'] = 1;
                                                            }
                                                        } catch (\Exception $e) {
                                                            logActivity("reset_to_autosubscribe: ERROR: get client domains: " . $e->getMessage());
                                                        }
                                                    }
                                                    /* Check the ordered modules */
                                                    $productGroups = [];
                                                    try {
                                                        $productGroups = Capsule::table('tblhosting')
                                                            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                                                            ->join('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
                                                            ->select('tblproductgroups.name as gname')
                                                            ->distinct()
                                                            ->where([
                                                                [ 'tblhosting.userid', '=', $clientId ]
                                                            ])
                                                            ->get();
                                                    } catch (\Exception $e) {
                                                        logActivity("ezchimp_output: ERROR: get client product groups 1: " . $e->getMessage());
                                                    }
                                                    if (!empty($productGroups)) {
                                                        foreach ($productGroups as $productGroup) {
                                                            $productGroupNames[$productGroup->gname] = 1;
                                                        }
                                                    }
                                                    if ($ezconf->debug > 1) {
                                                        logActivity("reset_to_autosubscribe: Product groups for $firstName $lastName [$email] ($clientId) - " . print_r($productGroupNames, true));
                                                    }
                                                    $subscribe_list_groups = [];
                                                    foreach ($productGroupNames as $productGroupName => $one) {
                                                        if (!empty($groupings[$productGroupName])) {
                                                            foreach ($groupings[$productGroupName] as $listId => $list_groupings) {
                                                                if ($ezconf->debug > 0) {
                                                                    logActivity("reset_to_autosubscribe: subscription group1 ($listId) - " . print_r($list_groupings, true));
                                                                }
                                                                if (!(is_array($list_groupings))) {
                                                                    if ($ezconf->debug > 0) {
                                                                        logActivity("reset_to_autosubscribe: empty main group");
                                                                    }
                                                                    if (!isset($subscribe_list_groups[$listId])) {
                                                                        $subscribe_list_groups[$listId] = [];
                                                                    }

                                                                    $fieldId = 0;
                                                                    try {
                                                                        $rows = Capsule::table('tblcustomfields')
                                                                            ->select('id')
                                                                            ->where([
                                                                                [ 'type', '=', 'client' ],
                                                                                [ 'fieldtype', '=', 'tickbox' ],
                                                                                [ 'sortorder', '=', 46306 ],
                                                                                [ 'fieldoptions', '=', $listId ]
                                                                            ])
                                                                            ->get();
                                                                        if ($rows[0]) {
                                                                            $fieldId = $rows[0]->id;
                                                                            if ($ezconf->debug > 0) {
                                                                                logActivity("reset_to_autosubscribe: get subscription field ID - $fieldId");
                                                                            }
                                                                        }
                                                                    } catch (\Exception $e) {
                                                                        logActivity("reset_to_autosubscribe: ERROR: get subscription field ID: " . $e->getMessage());
                                                                    }
                                                                    if ($fieldId) {
                                                                        $subscribed1 = [];
                                                                        try {
                                                                            $rows = Capsule::table('tblcustomfieldsvalues')
                                                                                ->select('relid')
                                                                                ->distinct()
                                                                                ->where([
                                                                                    [ 'fieldid', '=', $fieldId ],
                                                                                    [ 'value', '=', 'on' ]
                                                                                ])
                                                                                ->get();
                                                                            foreach ($rows as $row) {
                                                                                $subscribed1[$row->relid] = 1;
                                                                            }
                                                                        } catch (\Exception $e) {
                                                                            logActivity("reset_to_autosubscribe: ERROR: get subscribed: " . $e->getMessage());
                                                                        }

                                                                        $subscribed2 = [];
                                                                        try {
                                                                            $rows = Capsule::table('tblcustomfieldsvalues')
                                                                                ->select('relid')
                                                                                ->distinct()
                                                                                ->where([
                                                                                    [ 'fieldid', '=', $fieldId ],
                                                                                    [ 'value', '=', '' ]
                                                                                ])
                                                                                ->get();
                                                                            foreach ($rows as $row) {
                                                                                $subscribed2[$row->relid] = 1;
                                                                            }
                                                                        } catch (\Exception $e) {
                                                                            logActivity("reset_to_autosubscribe: ERROR: get unsubscribed: " . $e->getMessage());
                                                                        }

                                                                        if (isset($subscribed2[$clientId])) {
                                                                            if ($ezconf->debug > 2) {
                                                                                logActivity("reset_to_autosubscribe: update subscription");
                                                                            }
                                                                            try {
                                                                                Capsule::table('tblcustomfieldsvalues')
                                                                                    ->where([
                                                                                        [ 'relid', '=', $clientId ],
                                                                                        [ 'fieldid', '=', $fieldId ]
                                                                                    ])
                                                                                    ->update([ 'value' => 'on' ]);
                                                                            } catch (\Exception $e) {
                                                                                logActivity("reset_to_autosubscribe: ERROR: update subscription: " . $e->getMessage());
                                                                            }
                                                                        } else if (!isset($subscribed1[$clientId])) {
                                                                            if ($ezconf->debug > 2) {
                                                                                logActivity("reset_to_autosubscribe: insert subscription - " . print_r($subscribed1, true));
                                                                            }
                                                                            try {
                                                                                Capsule::table('tblcustomfieldsvalues')
                                                                                    ->insert([
                                                                                        'relid' => $clientId,
                                                                                        'fieldid' => $fieldId,
                                                                                        'value' => 'on'
                                                                                    ]);
                                                                            } catch (\Exception $e) {
                                                                                logActivity("reset_to_autosubscribe: ERROR: insert subscription: " . $e->getMessage());
                                                                            }
                                                                        } else {
                                                                            if ($ezconf->debug > 2) {
                                                                                logActivity("reset_to_autosubscribe: list already subscribed");
                                                                            }
                                                                        }
                                                                    }
                                                                } else {
                                                                    if (!isset($subscribe_list_groups[$listId])) {
                                                                        $subscribe_list_groups[$listId] = $list_groupings;
                                                                        $fresh_list = true;
                                                                    } else {
                                                                        $fresh_list = false;
                                                                    }
                                                                    foreach ($list_groupings as $mainGroup => $groups) {
                                                                        if (!$fresh_list) {
                                                                            if (!isset($subscribe_list_groups[$listId][$mainGroup])) {
                                                                                $subscribe_list_groups[$listId][$mainGroup] = $groups;
                                                                            } else {
                                                                                $subscribe_list_groups[$listId][$mainGroup] = array_unique(array_merge($subscribe_list_groups[$listId][$mainGroup], $groups));
                                                                                if ($ezconf->debug > 4) {
                                                                                    logActivity("reset_to_autosubscribe: New sub groups ($listId > $mainGroup): " . print_r($subscribe_list_groups[$listId][$mainGroup], true));
                                                                                }
                                                                            }
                                                                        }
                                                                        foreach ($groups as $group) {
                                                                            $fieldId = 0;
                                                                            try {
                                                                                $listStr = $listId.'^:'.$mainGroup.'^:'.$group;
                                                                                $rows = Capsule::table('tblcustomfields')
                                                                                    ->select('id')
                                                                                    ->where([
                                                                                        [ 'type', '=', 'client' ],
                                                                                        [ 'fieldtype', '=', 'tickbox' ],
                                                                                        [ 'sortorder', '=', 46306 ],
                                                                                        [ 'fieldoptions', '=', $listStr ]
                                                                                    ])
                                                                                    ->get();
                                                                                if ($rows[0]) {
                                                                                    $fieldId = $rows[0]->id;
                                                                                    if ($ezconf->debug > 0) {
                                                                                        logActivity("reset_to_autosubscribe: subscription group field ID - $fieldId");
                                                                                    }
                                                                                }
                                                                            } catch (\Exception $e) {
                                                                                logActivity("reset_to_autosubscribe: ERROR: get subscription group field ID: " . $e->getMessage());
                                                                            }

                                                                            if ($fieldId) {
                                                                                $subscribed1 = [];
                                                                                try {
                                                                                    $rows = Capsule::table('tblcustomfieldsvalues')
                                                                                        ->select('relid')
                                                                                        ->distinct()
                                                                                        ->where([
                                                                                            [ 'fieldid', '=', $fieldId ],
                                                                                            [ 'value', '=', 'on' ]
                                                                                        ])
                                                                                        ->get();
                                                                                    foreach ($rows as $row) {
                                                                                        $subscribed1[$row->relid] = 1;
                                                                                    }
                                                                                } catch (\Exception $e) {
                                                                                    logActivity("reset_to_autosubscribe: ERROR: get subscribed group: " . $e->getMessage());
                                                                                }

                                                                                $subscribed2 = [];
                                                                                try {
                                                                                    $rows = Capsule::table('tblcustomfieldsvalues')
                                                                                        ->select('relid')
                                                                                        ->distinct()
                                                                                        ->where([
                                                                                            [ 'fieldid', '=', $fieldId ],
                                                                                            [ 'value', '=', '' ]
                                                                                        ])
                                                                                        ->get();
                                                                                    foreach ($rows as $row) {
                                                                                        $subscribed2[$row->relid] = 1;
                                                                                    }
                                                                                } catch (\Exception $e) {
                                                                                    logActivity("reset_to_autosubscribe: ERROR: get unsubscribed group: " . $e->getMessage());
                                                                                }

                                                                                if (isset($subscribed2[$clientId])) {
                                                                                    if ($ezconf->debug > 2) {
                                                                                        logActivity("reset_to_autosubscribe: update subscription group");
                                                                                    }
                                                                                    try {
                                                                                        Capsule::table('tblcustomfieldsvalues')
                                                                                            ->where([
                                                                                                [ 'relid', '=', $clientId ],
                                                                                                [ 'fieldid', '=', $fieldId ]
                                                                                            ])
                                                                                            ->update([ 'value' => 'on' ]);
                                                                                    } catch (\Exception $e) {
                                                                                        logActivity("reset_to_autosubscribe: ERROR: update subscription group: " . $e->getMessage());
                                                                                    }
                                                                                } else if (!isset($subscribed1[$clientId])) {
                                                                                    if ($ezconf->debug > 2) {
                                                                                        logActivity("reset_to_autosubscribe: insert subscription group - " . print_r($subscribed1, true));
                                                                                    }
                                                                                    try {
                                                                                        Capsule::table('tblcustomfieldsvalues')
                                                                                            ->insert([
                                                                                                'relid' => $clientId,
                                                                                                'fieldid' => $fieldId,
                                                                                                'value' => 'on'
                                                                                            ]);
                                                                                    } catch (\Exception $e) {
                                                                                        logActivity("reset_to_autosubscribe: ERROR: insert subscription group: " . $e->getMessage());
                                                                                    }
                                                                                } else {
                                                                                    if ($ezconf->debug > 2) {
                                                                                        logActivity("reset_to_autosubscribe: list group already subscribed");
                                                                                    }
                                                                                }
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        } else {
                                                            $errors[] = "Product group [" . $productGroupName . "] lists not found in map";
                                                        }
                                                    }
                                                    if ($ezconf->debug > 4) {
                                                        logActivity("reset_to_autosubscribe: Subscriptions for $firstName $lastName [$email] ($clientId) - " . print_r($subscribe_list_groups, true));
                                                    }
                                                    if (!empty($subscribe_list_groups)) {
                                                        foreach ($subscribe_list_groups as $list_id => $interest_groupings) {
                                                            $subscription_groupings = [];
                                                            foreach ($interest_groupings as $mainGroup => $sub_groups) {
                                                                $subscription_groupings[] = [ 'name' => $mainGroup, 'groups' => $sub_groups ];
                                                            }
                                                            $subscription = [ 'list' => $list_id, 'grouping' => $subscription_groupings ];
                                                            _ezchimp_subscribe($subscription, $firstName, $lastName, $email, $emailType, $ezvars);
                                                            // TODO: subscribe contacts if configured
                                                        }
                                                    }
                                                }
                                            }
                                            $offset += $limit;
                                        } while ($offset < $clientCount);
                                    }
                                    if ($ezconf->debug > 0) {
                                        logActivity("reset_to_autosubscribe: errors - ".print_r($errors, true));
                                    }
                                    echo '<div class="infobox"><strong>'.$LANG['subscriptions_reset'].'</strong></div>';
                                } else {
                                    if ($ezconf->debug > 0) {
                                        logActivity("ezchimp_output: $action - No active lists/groups 2");
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
			<input type="radio" name="action" value="subscribe_empty_optin" class="ui-button ui-widget ui-state-default ui-corner-all" role="button" aria-disabled="false">
		</td>
		<td class="fieldarea">'.$LANG['subscribe_empty_optin_desc'].'</td>
	</tr>
	<tr>
		<td class="fieldlabel">
			<input type="radio" name="action" value="subscribe_empty_all_optin" class="ui-button ui-widget ui-state-default ui-corner-all" role="button" aria-disabled="false">
		</td>
		<td class="fieldarea">'.$LANG['subscribe_empty_all_optin_desc'].'</td>
	</tr>
	<tr>
		<td class="fieldlabel">
			<input type="radio" name="action" value="subscribe_empty_hosting_active" class="ui-button ui-widget ui-state-default ui-corner-all" role="button" aria-disabled="false">
		</td>
		<td class="fieldarea">'.$LANG['subscribe_empty_hosting_active_desc'].'</td>
	</tr>
	<tr>
		<td class="fieldlabel">
			<input type="radio" name="action" value="subscribe_empty_hosting_active_optin" class="ui-button ui-widget ui-state-default ui-corner-all" role="button" aria-disabled="false">
		</td>
		<td class="fieldarea">'.$LANG['subscribe_empty_hosting_active_optin_desc'].'</td>
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
	    		$lists = $list_names = [];
	    		$params = [ 'apikey' => $apikey ];
	    		$lists_result = _ezchimp_lists($params, $ezvars);
	    		if ($ezconf->debug > 3) {
	    			logActivity("ezchimp_output: lists result 1 - ".print_r($lists_result, true));
                }
                $interestmaps = [];
	    		if (!empty($lists_result)) {
	    			foreach ($lists_result as $list) {
                        logActivity("ezchimp_output: list - ".print_r($list, true));
	    				$params['id'] = $list->id;
                        $list_groupings = [];
                        $groupings = _ezchimp_listInterestGroupings($params, $ezvars);
                        if (!empty($groupings['groupings'])) {
                            $list_groupings = $groupings['groupings'];
                        }
                        $interestmaps[$list->id] = $groupings['interestmap'];
	    				$lists[$list->name] = [ 'id' => $list->id, 'groupings' => $list_groupings ];
                        $list_names[$list->id] = $list->name;
	    			}
	    		}
	    		if ($ezconf->debug > 2) {
	    			logActivity("ezchimp_output: lists - ".print_r($lists, true));
	    		}

	    		if (empty($lists)) {
	    			echo '<div class="errorbox"><strong>'.$LANG['create_list'].'</strong></div>';
	    		} else {
	    			$showOrder = isset($settings['showorder']) ? $settings['showorder'] : '';
	    			if (!empty($settings['activelists'])) {
	    				$activelists = unserialize($settings['activelists']);
				    	if ($ezconf->debug > 1) {
				    		logActivity("ezchimp_output: activelists 3 - ".print_r($activelists, true));
				    	}
	    			} else {
	    				$activelists = [];
	    			}
				    if (!empty($_POST)) {
                        /* save the interestmap */
                        try {
                            Capsule::table('mod_ezchimp')
                                ->where([
                                    [ 'setting', '=', 'interestmaps' ]
                                ])
                                ->update([ 'value' => serialize($interestmaps) ]);
                        } catch (\Exception $e) {
                            logActivity("ezchimp_output: ERROR: update interestmap: " . $e->getMessage());
                        }
		    			$saved = false;
                        $removed_webhooks = [];
                        $activelists_update = [];
				    	if (!empty($_POST['activelists'])) {
					    	if ($ezconf->debug > 4) {
					    		logActivity("ezchimp_output: POST 1 - ".print_r($_POST, true));
					    	}

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
					    				// (NOT USED) $list_name = $list_parts[1];
					    				$mainGroup = $parts[1];
					    				$group = $parts[2];
					    				$alias = empty($_POST['aliases'][md5($list)]) ? $group : $_POST['aliases'][md5($list)];
					    				$activelists_update[$list_id][$mainGroup][$group] = $alias;
					    				$list_str = $list_id.'^:'.$mainGroup.'^:'.$group;
					    			}
				    			}
				    			if ('' != $list_str) {
                                    try {
                                        $rows = Capsule::table('tblcustomfields')
                                            ->select('id', 'fieldname')
                                            ->where([
                                                [ 'type', '=', 'client' ],
                                                [ 'fieldtype', '=', 'tickbox' ],
                                                [ 'sortorder', '=', 46306 ],
                                                [ 'fieldoptions', '=', $list_str ]
                                            ])
                                            ->get();
                                        if ($rows[0]) {
                                            /* Update field name if it has changed */
                                            $fieldName = $rows[0]->fieldname;
                                            if ($LANG['subscribe_to'].$alias != $fieldName) {
                                                $customFieldId = $rows[0]->id;
                                                try {
                                                    Capsule::table('tblcustomfields')
                                                        ->where([
                                                            [ 'id', '=', $customFieldId ]
                                                        ])
                                                        ->update([ 'fieldname' => $LANG['subscribe_to'].$alias ]);
                                                    if ($ezconf->debug > 1) {
                                                        logActivity("ezchimp_output: update alias - $fieldName -> " . $LANG['subscribe_to'].$alias);
                                                    }
                                                } catch (\Exception $e) {
                                                    logActivity("ezchimp_output: ERROR: update alias: " . $e->getMessage());
                                                }
                                            }
                                        } else {
                                            try {
                                                Capsule::table('tblcustomfields')
                                                    ->insert([
                                                        'type' => 'client',
                                                        'relid' => 0,
                                                        'fieldname' => $LANG['subscribe_to'].$alias,
                                                        'fieldtype' => 'tickbox',
                                                        'description' => $LANG['subscribe_to_list'],
                                                        'fieldoptions' => $list_str,
                                                        'regexpr' => '',
                                                        'adminonly' => '',
                                                        'required' => '',
                                                        'showorder' => $showOrder,
                                                        'showinvoice' => '',
                                                        'sortorder' => 46306
                                                    ]);
                                            } catch (\Exception $e) {
                                                logActivity("ezchimp_output: ERROR: insert custom field: " . $e->getMessage());
                                            }
                                        }
                                    } catch (\Exception $e) {
                                        logActivity("ezchimp_output: ERROR: get subscription field ID 1: " . $e->getMessage());
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
                                    $params = [
                                        'apikey' => $apikey,
                                        'id' => $list_id,
                                        'url' => $vars['baseurl']."/ezchimp_webhook.php"
                                    ];
                                    _ezchimp_listWebhookDel($params, $ezvars);
                                    $removed_webhooks[$list_id] = true;
                                }
			    				if (!is_array($maingroups)) {
			    					/* This is a list without groups */
			    					if (!isset($activelists_update[$list_id]) || is_array($activelists_update[$list_id])) {
			    						$list_str = $list_id;
                                        try {
                                            $rows = Capsule::table('tblcustomfields')
                                                ->select('id')
                                                ->where([
                                                    [ 'type', '=', 'client' ],
                                                    [ 'fieldtype', '=', 'tickbox' ],
                                                    [ 'sortorder', '=', 46306 ],
                                                    [ 'fieldoptions', '=', $list_str ]
                                                ])
                                                ->get();
                                            if ($rows[0]) {
                                                $customFieldId = $rows[0]->id;
                                                if ($ezconf->debug > 0) {
                                                    logActivity("ezchimp_output: delete custom field ID - $customFieldId");
                                                }
                                                try {
                                                    Capsule::table('tblcustomfieldsvalues')
                                                        ->where([
                                                            [ 'fieldid', '=', $customFieldId ]
                                                        ])
                                                        ->delete();
                                                    Capsule::table('tblcustomfields')
                                                        ->where([
                                                            [ 'id', '=', $customFieldId ]
                                                        ])
                                                        ->delete();
                                                    if ($ezconf->debug > 1) {
                                                        logActivity("ezchimp_output: delete active main group - $list_str");
                                                    }
                                                } catch (\Exception $e) {
                                                    logActivity("ezchimp_output: ERROR: delete active: " . $e->getMessage());
                                                }
                                            }
                                        } catch (\Exception $e) {
                                            logActivity("ezchimp_output: ERROR: get subscription field ID 2: " . $e->getMessage());
                                        }
			    					}
			    				} else {
			    					foreach ($maingroups as $mainGroup => $groups) {
			    						foreach ($groups as $group => $alias) {
				    						if (!isset($activelists_update[$list_id][$mainGroup][$group])) {
				    							$list_str = $list_id.'^:'.$mainGroup.'^:'.$group;
                                                try {
                                                    $rows = Capsule::table('tblcustomfields')
                                                        ->select('id')
                                                        ->where([
                                                            [ 'type', '=', 'client' ],
                                                            [ 'fieldtype', '=', 'tickbox' ],
                                                            [ 'sortorder', '=', 46306 ],
                                                            [ 'fieldoptions', '=', $list_str ]
                                                        ])
                                                        ->get();
                                                    if ($rows[0]) {
                                                        $customFieldId = $rows[0]->id;
                                                        if ($ezconf->debug > 0) {
                                                            logActivity("ezchimp_output: delete custom field ID sub group - $customFieldId");
                                                        }
                                                        try {
                                                            Capsule::table('tblcustomfieldsvalues')
                                                                ->where([
                                                                    [ 'fieldid', '=', $customFieldId ]
                                                                ])
                                                                ->delete();
                                                            Capsule::table('tblcustomfields')
                                                                ->where([
                                                                    [ 'id', '=', $customFieldId ]
                                                                ])
                                                                ->delete();
                                                            if ($ezconf->debug > 1) {
                                                                logActivity("ezchimp_output: delete active sub group - $list_str");
                                                            }
                                                        } catch (\Exception $e) {
                                                            logActivity("ezchimp_output: ERROR: delete active sub group: " . $e->getMessage());
                                                        }
                                                    }
                                                } catch (\Exception $e) {
                                                    logActivity("ezchimp_output: ERROR: get subscription field ID 3: " . $e->getMessage());
                                                }
				    						}
			    						}
			    					}
			    				}
			    			}
				    	}

                        $webhooks_failed = [];
                        /* Add web hook for active lists */
                        if (!empty($activelists_update) && !empty($vars['baseurl'])) {
                            foreach (array_keys($activelists_update) as $list_id) {
                                if (!isset($removed_webhooks[$list_id])) {
                                    $params = [
                                        'apikey' => $apikey,
                                        'id' => $list_id,
                                        'url' => $vars['baseurl']."/ezchimp_webhook.php"
                                    ];
                                    _ezchimp_listWebhookDel($params, $ezvars);
                                }
                                $params = [
                                    'apikey' => $apikey,
                                    'id' => $list_id,
                                    'webhook' => [
                                        'url' => $vars['baseurl']."/ezchimp_webhook.php",
                                        'events' => [
                                            'subscribe' => false,
                                            'unsubscribe' => true,
                                            'profile' => false,
                                            'cleaned' => false,
                                            'upemail' => false,
                                            'campaign' => false
                                        ],
                                        "sources" => [
                                            "user" => true,
                                            "admin" => true,
                                            "api" => true
                                        ]
                                    ]
                                ];
                                if (!_ezchimp_listWebhookAdd($params, $ezvars)) {
                                    $webhooks_failed[] = $list_id;
                                }
                            }
                        }
				    	$value = serialize($activelists_update);
                        try {
                            Capsule::table('mod_ezchimp')
                                ->where([
                                    [ 'setting', '=', 'activelists' ]
                                ])
                                ->update([ 'value' => $value ]);
                            $saved = true;
                        } catch (\Exception $e) {
                            logActivity("ezchimp_output: ERROR: update activelists: " . $e->getMessage());
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
	    			$listnames = [];
	    			foreach ($lists as $name => $list) {
	    				$listnames[$list['id']] = $name;
	    				$no_groups = true;
	    				foreach ($list['groupings'] as $mainGroup) {
	    					if (!empty($mainGroup)) {
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
	    					foreach ($list['groupings'] as $mainGroup => $groups) {
	    						foreach ($groups as $group) {
	    							$interest_group = $list['id'].'%#'.$name.'^:'.$mainGroup.'^:'.$group;
	    							echo '<tr>
	    <td width="20%" class="fieldlabel"><input type="text" name="aliases['.md5($interest_group).']" value="'.$activelists[$list['id']][$mainGroup][$group].'" /></td>
	    <td class="fieldarea"><input type="checkbox" name="activelists[]" value="'.$interest_group.'"';
	    							if (isset($activelists[$list['id']][$mainGroup][$group])) {
	    								echo ' checked="checked"';
	    							}
	    							echo ' /> '.$name.' &gt; '.$mainGroup.' &gt; '.$group.'</td></tr>';
	    						}
	    					}
	    				}
	    			}
	    			if ($ezconf->debug > 1) {
	    				logActivity("ezchimp_output: listnames 2 - ".print_r($listnames, true));
	    			}
	    			$value = serialize($listnames);
                    try {
                        Capsule::table('mod_ezchimp')
                            ->where([
                                [ 'setting', '=', 'listnames' ]
                            ])
                            ->update([ 'value' => $value ]);
                    } catch (\Exception $e) {
                        logActivity("ezchimp_output: ERROR: update listnames: " . $e->getMessage());
                    }
	    			echo '
	</tr>
</table><p align="center"><input type="submit" value="'.$LANG['save'].'" class="ui-button ui-widget ui-state-default ui-corner-all" role="button" aria-disabled="false"></p></form>';
	    		}
	    		break;

            case 'affiliatelists':
                $lists = [];
                $params = [ 'apikey' => $apikey ];
	    		$lists_result = _ezchimp_lists($params, $ezvars);
                if ($ezconf->debug > 3) {
                    logActivity("ezchimp_output: affiliate lists result 1 - ".print_r($lists_result, true));
                }
                if (!empty($lists_result)) {
                    foreach ($lists_result as $list) {
                        $params['id'] = $list->id;
                        $list_groupings = [];
                        $groupings = _ezchimp_listInterestGroupings($params, $ezvars);
                        if (!empty($groupings['groupings'])) {
                            $list_groupings = $groupings['groupings'];
                        }
                        $lists[$list->name] = [ 'id' => $list->id, 'groupings' => $list_groupings ];
                    }
                }
                if ($ezconf->debug > 2) {
                    logActivity("ezchimp_output: affiliate lists - ".print_r($lists, true));
                }

                if (empty($lists)) {
                    echo '<div class="errorbox"><strong>'.$LANG['create_list'].'</strong></div>';
                } else {
                    if (!empty($settings['affiliatelists'])) {
                        $affiliatelists = unserialize($settings['affiliatelists']);
                        if ($ezconf->debug > 1) {
                            logActivity("ezchimp_output: affiliatelists - ".print_r($affiliatelists, true));
                        }
                    } else {
                        $affiliatelists = [];
                    }
                    if (!empty($_POST)) {
                        $saved = false;
                        $affiliatelists_update = [];
                        if (!empty($_POST['affiliatelists'])) {
                            if ($ezconf->debug > 4) {
                                logActivity("ezchimp_output: affiliate lists POST - ".print_r($_POST, true));
                            }

                            foreach ($_POST['affiliatelists'] as $list) {
                                if (strpos($list, '^:') === false) {
                                    $list_parts = explode('%#', $list);
                                    $list_id = $list_parts[0];
                                    // (NOT USED) $list_name = $list_parts[1];
                                    $affiliatelists_update[$list_id] = true;
                                } else {
                                    $parts = explode('^:', $list);
                                    if (!empty($parts[2])) {
                                        $list_parts = explode('%#', $parts[0]);
                                        $list_id = $list_parts[0];
                                        // (NOT USED) $list_name = $list_parts[1];
                                        $mainGroup = $parts[1];
                                        $group = $parts[2];
                                        $affiliatelists_update[$list_id][$mainGroup][$group] = true;
                                    }
                                }
                            }
                        }

                        if ($ezconf->debug > 0) {
                            logActivity("ezchimp_output: affiliatelists update - ".print_r($affiliatelists_update, true));
                        }
                        $value = serialize($affiliatelists_update);
                        try {
                            Capsule::table('mod_ezchimp')
                                ->where([
                                    [ 'setting', '=', 'affiliatelists' ]
                                ])
                                ->update([ 'value' => $value ]);
                            $saved = true;
                        } catch (\Exception $e) {
                            logActivity("ezchimp_output: ERROR: update affiliatelists: " . $e->getMessage());
                        }
                    }

                    echo '<br /><h2>'.$LANG['affiliate_lists_groups'].'</h2><p>'.$LANG['affiliate_lists_groups_desc'].'</p>';
                    if (!empty($_POST)) {
                        if ($saved) {
                            echo '<div class="infobox"><strong>'.$LANG['saved'].'</strong><br>'.$LANG['saved_desc'].'</div>';
                            $affiliatelists = $affiliatelists_update;
                        } else {
                            echo '<div class="errorbox"><strong>'.$LANG['save_failed'].'</strong><br>'.$LANG['save_failed_desc'].'</div>';
                        }
                    }
                    echo '<form name="ezchimpAffiliateLists" method="POST"><table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">
	<tr>
		<th>'.$LANG['interest_groups'].'</th>
	</tr>';
                    foreach ($lists as $name => $list) {
                        $no_groups = true;
                        foreach ($list['groupings'] as $mainGroup) {
                            if (!empty($mainGroup)) {
                                $no_groups = false;
                                break;
                            }
                        }
                        if ($no_groups) {
                            $interest_group = $list['id'].'%#'.$name;
                            echo '<tr>
	    <td class="fieldarea"><input type="checkbox" name="affiliatelists[]" value="'.$interest_group.'"';
                            if (isset($affiliatelists[$list['id']])) {
                                echo ' checked="checked"';
                            }
                            echo ' /> '.$name.'</td></tr>';
                        } else {
                            foreach ($list['groupings'] as $mainGroup => $groups) {
                                foreach ($groups as $group) {
                                    $interest_group = $list['id'].'%#'.$name.'^:'.$mainGroup.'^:'.$group;
                                    echo '<tr>
	    <td class="fieldarea"><input type="checkbox" name="affiliatelists[]" value="'.$interest_group.'"';
                                    if (isset($affiliatelists[$list['id']][$mainGroup][$group])) {
                                        echo ' checked="checked"';
                                    }
                                    echo ' /> '.$name.' &gt; '.$mainGroup.' &gt; '.$group.'</td></tr>';
                                }
                            }
                        }
                    }
                    echo '
	</tr>
</table><p align="center"><input type="submit" value="'.$LANG['save'].'" class="ui-button ui-widget ui-state-default ui-corner-all" role="button" aria-disabled="false"></p></form>';
                }
                break;

           case 'autosubscribe':
               $productGroups = [ 'Domains' ];
               $flag = false;
               try {
                   $rows = Capsule::table('tblproductgroups')
                       ->select('name')
                       ->get();
                   foreach ($rows as $row) {
                       $productGroups[] = $row->name;
                   }
               } catch (\Exception $e) {
                   logActivity("ezchimp_output: ERROR: get client product groups 2: " . $e->getMessage());
               }

               if (empty($productGroups)) {
                   echo '<div class="errorbox"><strong>' . $LANG['add_product_groups'] . ' <a href="configproducts.php">' . $LANG['product_setup'] . '</a></strong></div>';
               } else {
                   $lists = $listnames = [];
                   $params = [ 'apikey' => $apikey ];
                   $lists_result = _ezchimp_lists($params, $ezvars);
                   if ($ezconf->debug > 3) {
                       logActivity("ezchimp_output: lists result 2 - " . print_r($lists_result, true));
                   }

                    if (!empty($settings['activelists'])) {
                        $activelists = unserialize($settings['activelists']);
                        if (!empty($activelists)) {
                            foreach ($activelists as $listid => $list){
                                if (!empty($lists_result)) {
                                    foreach ($lists_result as $listname) {
                                        if(!(strcmp($listname->id,$listid))){
                                            $lists[$listname->name] = [ 'id' => $listid, 'groupings' => $list ];
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
                                logActivity("ezchimp_output: POST 2 - " . print_r($_POST, true));
                            }
                            $groupings = $groupings1 = [];
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
                            if ($ezconf->debug > 0) {
                                logActivity("ezchimp_output: groupings1 update - " . print_r($groupings1, true));
                            }

                            foreach ($groupings as $lid => $l1) {
                                $l2 = $groupings1[$lid];
                                foreach ($l1 as $m1) {
                                    if (!(is_array($m1))) {
                                        foreach ($l2 as $m2) {
                                            if (!(is_array($m2))) {
                                                if (!(strcmp($m1, $m2))) {
                                                    $flag = true;
                                                    if ($ezconf->debug > 0) {
                                                        logActivity("ezchimp_output: common grps1 - " . print_r($m1, true));
                                                    }
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

                            if ($ezconf->debug > 0) {
                                logActivity("ezchimp_output: subscribed update - " . print_r($groupings, true));
                            }
                            if ($flag) {
                                if ($ezconf->debug > 0) {
                                    logActivity("ezchimp_output: common groups" );
                                }
                            } else {
                                $value = serialize($groupings);
                                try {
                                    Capsule::table('mod_ezchimp')
                                        ->where([
                                            ['setting', '=', 'groupings']
                                        ])
                                        ->update(['value' => $value]);
                                    $saved = true;
                                } catch (\Exception $e) {
                                    logActivity("ezchimp_output: ERROR: update groupings: " . $e->getMessage());
                                }

                                if ($ezconf->debug > 0) {
                                    logActivity("ezchimp_output: unsubscribed update - " . print_r($groupings1, true));
                                }
                                $value = serialize($groupings1);
                                try {
                                    Capsule::table('mod_ezchimp')
                                        ->where([
                                            ['setting', '=', 'unsubscribe_groupings']
                                        ])
                                        ->update(['value' => $value]);
                                    $saved1 = true;
                                } catch (\Exception $e) {
                                    logActivity("ezchimp_output: ERROR: update unsubscribe_groupings: " . $e->getMessage());
                                }
                            }
                        } else {
                            $groupings = unserialize($settings['groupings']);
                            $groupings1 = unserialize($settings['unsubscribe_groupings']);
                        }
                        echo '<br /><h2>'.$LANG['product_interest_grouping'].'</h2><p>'.$LANG['product_interest_grouping_desc'].'</p>';
                        if (!empty($_POST)) {
                            if ($flag) {
                                echo '<div class="infobox"><strong>'.$LANG['common_select'].'</strong></div>';
                            }
                            if ($saved || $saved1) {
                                echo '<div class="infobox"><strong>'.$LANG['saved'].'</strong><br>'.$LANG['saved_desc'].'</div>';
                            } else {
                                echo '<div class="errorbox"><strong>'.$LANG['save_failed'].'</strong><br>'.$LANG['save_failed_desc'].'</div>';
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

                        foreach ($productGroups as $productGroup) {
                            echo '
	<tr>
		<td align="center" width="10%" class="fieldlabel">';
                            if ('Domains' == $productGroup) {
                                echo $LANG['domains'];
                            } else {
                                echo $productGroup;
                            }
                            echo '</td>
                        <td width="10%" class="fieldarea">
                        <select class="grouping1" multiple="multiple" name="groupings1[]">';
                        foreach ($lists as $list) {
                            $no_groups = true;
                            foreach ($list['groupings'] as $mainGroup) {
                                if (!empty($mainGroup)) {
                                    $no_groups = false;
                                    break;
                                }
                            }
                            if ($no_groups) {
                                echo '<option value="'.$productGroup.'^:'.$list['id'].'"';
                                if (isset($groupings1[$productGroup][$list['id']])) {
                                    echo ' selected="selected"';
                                }
                                echo '> '.$list['groupings'].'</option>';
                            } else {
                                foreach ($list['groupings'] as $mainGroup => $groups) {
                                    foreach ($groups as $group) {
                                        echo '<option value="'.$productGroup.'^:'.$list['id'].'^:'.$mainGroup.'^:'.$group.'"';
                                        if (!empty($groupings1[$productGroup][$list['id']][$mainGroup]) && in_array($group, $groupings1[$productGroup][$list['id']][$mainGroup])) {
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
                                foreach ($list['groupings'] as $mainGroup) {
                                    if (!empty($mainGroup)) {
                                        $no_groups = false;
                                        break;
                                    }
                                }
                                if ($no_groups) {
                                    echo '<option value="'.$productGroup.'^:'.$list['id'].'"';
                                    if (isset($groupings[$productGroup][$list['id']])) {
                                        echo ' selected="selected"';
                                    }
                                    echo '> '.$list['groupings'].'</option>';
                                }
                                else{
                                    foreach ($list['groupings'] as $mainGroup => $groups) {
                                        foreach ($groups as $group) {
                                            echo '<option value="'.$productGroup.'^:'.$list['id'].'^:'.$mainGroup.'^:'.$group.'"';
                                            if (!empty($groupings[$productGroup][$list['id']][$mainGroup]) && in_array($group, $groupings[$productGroup][$list['id']][$mainGroup])) {
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
	    			$settings_update = [];

			    	if (isset($_POST['delete_member']) && ('on' == $_POST['delete_member'])) {
			    		$settings_update['delete_member'] = 'on';
			    	} else {
			    		$settings_update['delete_member'] = '';
			    	}

			    	if (isset($_POST['format_select']) && ('on' == $_POST['format_select'])) {
			    		$settings_update['format_select'] = 'on';
			    		if (isset($settings_update['showorder'])) {
			    			$showOrder = $settings_update['showorder'];
			    		} else if (isset($settings['showorder'])) {
			    			$showOrder = $settings['showorder'];
			    		} else {
			    			$showOrder = '';
			    		}
                        try {
                            Capsule::table('tblcustomfields')
                                ->where([
                                    [ 'type', '=', 'client' ],
                                    [ 'fieldtype', '=', 'dropdown' ],
                                    [ 'sortorder', '=', 46307 ]
                                ])
                                ->update([
                                    'adminonly' => '',
                                    'showorder' => $showOrder
                                ]);
                        } catch (\Exception $e) {
                            logActivity("ezchimp_output: ERROR: update email format custom field 3: " . $e->getMessage());
                        }
			    	} else {
			    		$settings_update['format_select'] = '';
                        try {
                            Capsule::table('tblcustomfields')
                                ->where([
                                    [ 'type', '=', 'client' ],
                                    [ 'fieldtype', '=', 'dropdown' ],
                                    [ 'sortorder', '=', 46307 ]
                                ])
                                ->update([
                                    'adminonly' => 'on',
                                    'showorder' => ''
                                ]);
                        } catch (\Exception $e) {
                            logActivity("ezchimp_output: ERROR: update email format custom field 4: " . $e->getMessage());
                        }
			    	}

			    	if (isset($_POST['subscribe_contacts']) && ('on' == $_POST['subscribe_contacts'])) {
			    		$settings_update['subscribe_contacts'] = 'on';
			    		if (isset($settings_update['showorder'])) {
			    			$showOrder = $settings_update['showorder'];
			    		} else if (isset($settings['showorder'])) {
			    			$showOrder = $settings['showorder'];
			    		} else {
			    			$showOrder = '';
			    		}
                        try {
                            Capsule::table('tblcustomfields')
                                ->where([
                                    [ 'type', '=', 'client' ],
                                    [ 'fieldtype', '=', 'tickbox' ],
                                    [ 'sortorder', '=', 46309 ]
                                ])
                                ->update([
                                    'adminonly' => '',
                                    'showorder' => $showOrder
                                ]);
                        } catch (\Exception $e) {
                            logActivity("ezchimp_output: ERROR: update subscribe all contacts custom field 3: " . $e->getMessage());
                        }
			    	} else {
			    		$settings_update['subscribe_contacts'] = '';
                        try {
                            Capsule::table('tblcustomfields')
                                ->where([
                                    [ 'type', '=', 'client' ],
                                    [ 'fieldtype', '=', 'tickbox' ],
                                    [ 'sortorder', '=', 46309 ]
                                ])
                                ->update([
                                    'adminonly' => 'on',
                                    'showorder' => ''
                                ]);
                        } catch (\Exception $e) {
                            logActivity("ezchimp_output: ERROR: update subscribe all contacts custom field 4: " . $e->getMessage());
                        }
			    	}

			    	if (isset($_POST['interest_select']) && ('on' == $_POST['interest_select'])) {
			    		$settings_update['interest_select'] = 'on';
			    		if (isset($settings_update['showorder'])) {
			    			$showOrder = $settings_update['showorder'];
			    		} else if (isset($settings['showorder'])) {
			    			$showOrder = $settings['showorder'];
			    		} else {
			    			$showOrder = '';
			    		}
                        try {
                            Capsule::table('tblcustomfields')
                                ->where([
                                    [ 'type', '=', 'client' ],
                                    [ 'fieldtype', '=', 'tickbox' ],
                                    [ 'sortorder', '=', 46306 ]
                                ])
                                ->update([
                                    'adminonly' => '',
                                    'showorder' => $showOrder
                                ]);
                        } catch (\Exception $e) {
                            logActivity("ezchimp_output: ERROR: update newsletter custom fields 2: " . $e->getMessage());
                        }
			    	} else {
			    		$settings_update['interest_select'] = '';
                        try {
                            Capsule::table('tblcustomfields')
                                ->where([
                                    [ 'type', '=', 'client' ],
                                    [ 'fieldtype', '=', 'tickbox' ],
                                    [ 'sortorder', '=', 46306 ]
                                ])
                                ->update([
                                    'adminonly' => 'on',
                                    'showorder' => ''
                                ]);
                        } catch (\Exception $e) {
                            logActivity("ezchimp_output: ERROR: update newsletter custom fields 3: " . $e->getMessage());
                        }
			    	}

			    	if (isset($_POST['showorder']) && ('on' == $_POST['showorder'])) {
			    		$settings_update['showorder'] = 'on';
                        try {
                            Capsule::table('tblcustomfields')
                                ->where([
                                    [ 'type', '=', 'client' ],
                                    [ 'fieldtype', '=', 'tickbox' ]
                                ])
                                ->whereIn('sortorder', [ 46306, 46307, 46309 ])
                                ->update([ 'showorder' => 'on' ]);
                        } catch (\Exception $e) {
                            logActivity("ezchimp_output: ERROR: update custom fields show order 1: " . $e->getMessage());
                        }
			    	} else {
			    		$settings_update['showorder'] = '';
                        try {
                            Capsule::table('tblcustomfields')
                                ->where([
                                    [ 'type', '=', 'client' ],
                                    [ 'fieldtype', '=', 'tickbox' ]
                                ])
                                ->whereIn('sortorder', [ 46306, 46307, 46309 ])
                                ->update([ 'showorder' => '' ]);
                        } catch (\Exception $e) {
                            logActivity("ezchimp_output: ERROR: update custom fields show order 2: " . $e->getMessage());
                        }
			    	}

			    	if (isset($_POST['default_subscribe']) && ('on' == $_POST['default_subscribe'])) {
			    		$settings_update['default_subscribe'] = 'on';
			    	} else {
			    		$settings_update['default_subscribe'] = '';
			    	}

			    	if (isset($_POST['default_format']) && (in_array($_POST['default_format'], [ 'html', 'text', 'mobile' ]))) {
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
			    		logActivity("ezchimp_output: update module settings - ".print_r($settings_update, true));
			    	}

			    	foreach ($settings_update as $setting => $value) {
                        try {
                            Capsule::table('mod_ezchimp')
                                ->where([
                                    [ 'setting', '=', $setting ]
                                ])
                                ->update([ 'value' => $value ]);
                        } catch (\Exception $e) {
                            logActivity("ezchimp_output: ERROR: update module setting: $setting - " . $e->getMessage());
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
		<td width="20%" class="fieldlabel">'.$LANG['delete_member'].'</td>
		<td class="fieldarea"><input type="checkbox" name="delete_member" value="on"';
			    if (isset($settings['delete_member']) && ('on' == $settings['delete_member'])) {
			    	echo ' checked="checked"';
			    }
				echo ' /> '.$LANG['delete_member_desc'].'</td>
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

/**
 * Return ezchimp config
 */
function _ezchimp_config(&$ezconf) {
	$config = [];
    try {
        $rows = Capsule::table('tbladdonmodules')
            ->select('setting', 'value')
            ->where('module', '=', 'ezchimp')
            ->get();
        foreach ($rows as $row) {
            $config[$row->setting] = $row->value;
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_config: ERROR: get module config: " . $e->getMessage());
    }
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
	$settings = [];
    try {
        $rows = Capsule::table('mod_ezchimp')
            ->select('setting', 'value')
            ->get();
        foreach ($rows as $row) {
            $settings[$row->setting] = $row->value;
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_settings: ERROR: get module settings: " . $e->getMessage());
    }
	if ($ezconf->debug > 0) {
		logActivity("_ezchimp_settings: module settings - ".print_r($settings, true));
	}
	return $settings;
}

function _ezchimp_listgroup_subscriptions($client_id, &$ezvars) {
    $fields = [];
    try {
        $rows = Capsule::table('tblcustomfields')
            ->select('id', 'fieldoptions')
            ->where([
                [ 'type', '=', 'client' ],
                [ 'fieldtype', '=', 'tickbox' ],
                [ 'sortorder', '=', 46306 ]
            ])
            ->get();
        foreach ($rows as $row) {
            $fields[$row->id] = $row->fieldoptions;
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_listgroup_subscriptions: ERROR: get field options: " . $e->getMessage());
    }
    if ($ezvars->debug > 2) {
        logActivity("_ezchimp_listgroup_subscriptions: fields - ".print_r($fields, true));
    }

    $list_groups = [];
    try {
        $rows = Capsule::table('tblcustomfieldsvalues')
            ->select('fieldid', 'value')
            ->where([
                [ 'relid', '=', $client_id ]
            ])
            ->get();
        foreach ($rows as $row) {
            if (isset($fields[$row->fieldid])) {
                $list = $fields[$row->fieldid];
                $status = $row->value;
                if ('on' == $status) {
                    if (strpos($list, '^:') === false) {
                        $list_groups[$list] = [];
                    } else {
                        $parts = explode('^:', $list);
                        if (!empty($parts[2])) {
                            $list_id = $parts[0];
                            $mainGroup = $parts[1];
                            $group = $parts[2];
                            $list_groups[$list_id][$mainGroup][] = $group;
                        }
                    }
                }
            }
        }
        if ($ezvars->debug > 2) {
            logActivity("_ezchimp_listgroup_subscriptions: list_groups - ".print_r($list_groups, true));
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_listgroup_subscriptions: ERROR: get custom field values: " . $e->getMessage());
    }

    $subscriptions = [];
    $all_lists = [];
    $params = [ 'apikey' => $ezvars->config['apikey'] ];
    $lists_result = _ezchimp_lists($params, $ezvars);
    if (!empty($lists_result)) {
        foreach ($lists_result as $list) {
            $all_lists[$list->id] = 1;
        }
    }
    if ($ezvars->debug > 2) {
        logActivity("_ezchimp_listgroup_subscriptions: all_lists - ".print_r($all_lists, true));
    }

    foreach ($list_groups as $list => $groups) {
        unset($all_lists[$list]);
        if (empty($groups)) {
            $subscriptions[] = [ 'list' => $list ];
        } else {
            $subscription_groupings = [];
            $all_groups = [];
            $params['id'] = $list;
            $groupings = _ezchimp_listInterestGroupings($params, $ezvars);
            if (!empty($groupings['groupings'])) {
                foreach ($groupings['groupings'] as $grouping) {
                    $all_groups[$grouping] = 1;
                }
            }
            if ($ezvars->debug > 2) {
                logActivity("_ezchimp_listgroup_subscriptions: all_groups - ".print_r($all_groups, true));
            }
            if ($ezvars->debug > 2) {
                logActivity("_ezchimp_listgroup_subscriptions: interest_groups - ".print_r($groupings, true));
            }
            foreach ($groups as $maingroup => $sub_groups) {
                unset($all_groups[$maingroup]);
                $subscription_groupings[] = [ 'name' => $maingroup, 'groups' => array_keys($sub_groups) ];
            }
            foreach ($all_groups as $gr) {
                if ($ezvars->debug > 2) {
                    logActivity("_ezchimp_listgroup_subscriptions: all_groups2 - ".print_r($gr, true));
                }
            }
            if (!empty($subscription_groupings)) {
                $subscriptions[] = [ 'list' => $list, 'grouping' => $subscription_groupings ];
            }
        }
    }
    if ($ezvars->debug > 3) {
        logActivity("_ezchimp_listgroup_subscriptions: remaining all_lists - ".print_r($all_lists, true));
    }
    foreach ($all_lists as $list => $one) {
        $subscriptions[] = [ 'list' => $list, 'unsubscribe' => 1 ];
    }
    if ($ezvars->debug > 1) {
        logActivity("_ezchimp_listgroup_subscriptions: subscriptions - ".print_r($subscriptions, true));
    }

    return $subscriptions;
}

function _ezchimp_client_email_type($client_id, &$ezvars) {
    $emailType = $ezvars->settings['default_format'];
    $fieldId = 0;
    try {
        $rows = Capsule::table('tblcustomfields')
            ->select('id')
            ->where([
                [ 'type', '=', 'client' ],
                [ 'fieldtype', '=', 'dropdown' ],
                [ 'sortorder', '=', 46307 ]
            ])
            ->get();
        if ($rows[0]) {
            $fieldId = $rows[0]->id;
        }
        if ($ezvars->debug > 1) {
            logActivity("_ezchimp_client_email_type: fieldId - $fieldId");
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_client_email_type: ERROR: get default mail format: " . $e->getMessage());
    }

    if ($fieldId > 0) {
        try {
            $rows = Capsule::table('tblcustomfieldsvalues')
                ->select('value')
                ->where([
                    [ 'fieldid', '=', $fieldId ],
                    [ 'relid', '=', $client_id ]
                ])
                ->get();
            if ($rows[0]) {
                $et = strtolower($rows[0]->value);
                if (('html' == $et) || ('text' == $et)) {
                    $emailType = $et;
                }
            }
            if ($ezvars->debug > 2) {
                logActivity("_ezchimp_client_email_type: email_type - $emailType");
            }
        } catch (\Exception $e) {
            logActivity("_ezchimp_client_email_type: ERROR: get field options: " . $e->getMessage());
        }
    }

    return $emailType;
}

function _ezchimp_client_subscribe_contacts($clientId, &$ezvars) {
    $subscribeContacts = '';
    $fieldId = 0;
    try {
        $rows = Capsule::table('tblcustomfields')
            ->select('id')
            ->where([
                [ 'type', '=', 'client' ],
                [ 'fieldtype', '=', 'tickbox' ],
                [ 'sortorder', '=', 46309 ]
            ])
            ->get();
        if ($rows[0]) {
            $fieldId = $rows[0]->id;
        }
        if ($ezvars->debug > 1) {
            logActivity("_ezchimp_client_subscribe_contacts: field_id - $fieldId");
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_client_subscribe_contacts: ERROR: get field ID: " . $e->getMessage());
    }

    if ($fieldId >0) {
        try {
            $rows = Capsule::table('tblcustomfieldsvalues')
                ->select('value')
                ->where([
                    [ 'fieldid', '=', $fieldId ],
                    [ 'relid', '=', $clientId ]
                ])
                ->get();
            if ($rows[0]) {
                $subscribeContacts = $rows[0]->value;
            }
            if ($ezvars->debug > 2) {
                logActivity("_ezchimp_client_subscribe_contacts: subscribecontacts - $subscribeContacts");
            }
        } catch (\Exception $e) {
            logActivity("_ezchimp_client_subscribe_contacts: ERROR: get field options: " . $e->getMessage());
        }
    }

    return $subscribeContacts;
}
