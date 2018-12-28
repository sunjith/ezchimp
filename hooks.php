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
www.admod.com www.supportmonk.com www.ezeelogin.com

**********************************************
*/

use Illuminate\Database\Capsule\Manager as Capsule;
require_once(__DIR__."/mailchimp.php");

class EzchimpVars {
    public $debug = 0;
    public $config = [];
    public $settings = [];
}

/**
 * Return ezchimp config
 */
function _ezchimp_get_config(&$ezvars) {
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
        logActivity("_ezchimp_get_config: ERROR: get module config: " . $e->getMessage());
    }
    if (isset($config['debug'])) {
        $ezvars->debug = intval($config['debug']);
    }
    if ($ezvars->debug > 0) {
        logActivity("_ezchimp_get_config: module config - ".print_r($config, true));
    }
    return $config;
}

/**
 * Return ezchimp settings
 */
function _ezchimp_get_settings(&$ezvars) {
    $settings = [];
    try {
        $rows = Capsule::table('mod_ezchimp')
            ->select('setting', 'value')
            ->get();
        foreach ($rows as $row) {
            $settings[$row->setting] = $row->value;
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_get_settings: ERROR: get module settings: " . $e->getMessage());
    }
    if ($ezvars->debug > 0) {
        logActivity("_ezchimp_get_settings: module settings - ".print_r($settings, true));
    }
    return $settings;
}

/**
 * Initialize global variables
 */
function _ezchimp_hook_init(&$ezvars) {
    $ezvars->config = _ezchimp_get_config($ezvars);
    $ezvars->settings = _ezchimp_get_settings($ezvars);
}

function _ezchimp_update_subscription($clientId, $listStr, $subscribe, &$ezvars) {
    if ($ezvars->debug > 4) {
        logActivity("_ezchimp_update_subscription: clientId - $clientId, listStr - $listStr, subscribe - $subscribe");
    }
    $fieldId = 0;
    try {
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
            if ($ezvars->debug > 0) {
                logActivity("_ezchimp_update_subscription: subscription field ID - $fieldId");
            }
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_update_subscription: ERROR: get subscription field ID: " . $e->getMessage());
    }

    $subscribed = [];
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
            $subscribed[$row->relid] = 1;
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_update_subscription: ERROR: get subscribed: " . $e->getMessage());
    }

    if ($subscribe) {
        $unsubscribed = [];
        try {
            $rows = Capsule::table('tblcustomfieldsvalues')
                ->select('relid')
                ->distinct()
                ->where([
                    ['fieldid', '=', $fieldId],
                    ['value', '=', '']
                ])
                ->get();
            foreach ($rows as $row) {
                $unsubscribed[$row->relid] = 1;
            }
        } catch (\Exception $e) {
            logActivity("_ezchimp_update_subscription: ERROR: get unsubscribed: " . $e->getMessage());
        }

        if (isset($unsubscribed[$clientId])) {
            if ($ezvars->debug > 2) {
                logActivity("_ezchimp_update_subscription: update subscription");
            }
            try {
                Capsule::table('tblcustomfieldsvalues')
                    ->where([
                        ['relid', '=', $clientId],
                        ['fieldid', '=', $fieldId]
                    ])
                    ->update(['value' => 'on']);
            } catch (\Exception $e) {
                logActivity("_ezchimp_update_subscription: ERROR: update subscription: " . $e->getMessage());
            }
        } else if (!isset($subscribed[$clientId])) {
            if ($ezvars->debug > 2) {
                logActivity("_ezchimp_update_subscription: insert subscription - " . print_r($subscribed, true));
            }
            try {
                Capsule::table('tblcustomfieldsvalues')
                    ->insert([
                        'relid' => $clientId,
                        'fieldid' => $fieldId,
                        'value' => 'on'
                    ]);
            } catch (\Exception $e) {
                logActivity("_ezchimp_update_subscription: ERROR: insert subscription: " . $e->getMessage());
            }
        } else {
            if ($ezvars->debug > 2) {
                logActivity("_ezchimp_update_subscription: list already subscribed");
            }
        }
    } else { // unsubscribe
        if (isset($subscribed[$clientId])) {
            if ($ezvars->debug > 2) {
                logActivity("_ezchimp_update_subscription: unsubscribe - " . print_r($subscribed, true));
            }
            try {
                Capsule::table('tblcustomfieldsvalues')
                    ->where([
                        [ 'relid', '=', $clientId ],
                        [ 'fieldid', '=', $fieldId ]
                    ])
                    ->update([ 'value' => '' ]);
            } catch (\Exception $e) {
                logActivity("_ezchimp_update_subscription: ERROR: unsubscribe: " . $e->getMessage());
            }
        }
    }
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
    $ezvars = new EzchimpVars();
    _ezchimp_hook_init($ezvars);

    if ($ezvars->debug > 0) {
        logActivity("ezchimp_hook_order: vars - " . print_r($vars, true));
    }
    $order_id = $vars['orderid'];
    $groupings = unserialize($ezvars->settings['groupings']);
    $groupings1 = unserialize($ezvars->settings['unsubscribe_groupings']);
    $errors = $client = $subscriptions = $subscriptions1 = $subscriptions2 = $subscriptions3 = $sub = $sub_list = $productGroupNames = [];

    if ($ezvars->debug > 0) {
        logActivity("ezchimp_hook_order: groupings - " . print_r($groupings, true));
        logActivity("ezchimp_hook_order: groupings1 - " . print_r($groupings1, true));
    }
    /* Check ordered domains if domains grouping available */
    if (!empty($groupings['Domains']) || !empty($groupings1['Domains'])) {
        try {
            $rows = Capsule::table('tbldomains')
                ->select('userid')
                ->where([
                    [ 'orderid', '=', $order_id ]
                ])
                ->get();
            if ($rows[0]) {
                $productGroupNames['Domains'] = 1;
                $clientId = $rows[0]->userid;
            }
        } catch (\Exception $e) {
            logActivity("ezchimp_hook_order: ERROR: get order domain ($order_id): " . $e->getMessage());
        }
    }
    /* Check the ordered modules */
    $modules = [];
    try {
        $modules = Capsule::table('tblhosting')
            ->select('userid', 'packageid')
            ->where([
                [ 'orderid', '=', $order_id ]
            ])
            ->get();
        if ($ezvars->debug > 3) {
            logActivity("ezchimp_hook_order: ordered modules ($order_id): " . print_r($modules, true));
        }
    } catch (\Exception $e) {
        logActivity("ezchimp_hook_order: ERROR: get order modules ($order_id): " . $e->getMessage());
    }
    if (!empty($modules)) {
        foreach ($modules as $module) {
            $clientId = $module->userid;
            $productId = $module->packageid;
            try {
                $products = Capsule::table('tblproducts')
                    ->select('gid')
                    ->where([
                        [ 'id', '=', $productId ]
                    ])
                    ->get();
                if ($ezvars->debug > 3) {
                    logActivity("ezchimp_hook_order: ordered products ($order_id, $clientId, $productId): " . print_r($products, true));
                }
                if ($products[0]) {
                    $productGroupId = $products[0]->gid;
                    try {
                        $productGroups = Capsule::table('tblproductgroups')
                            ->select('name')
                            ->where([
                                [ 'id', '=', $productGroupId ]
                            ])
                            ->get();
                        if ($ezvars->debug > 3) {
                            logActivity("ezchimp_hook_order: ordered product group ($order_id, $clientId, $productId, $productGroupId): " . print_r($productGroups, true));
                        }
                        if ($productGroups[0]) {
                            $productGroupNames[$productGroups[0]->name] = 1;
                        } else {
                            $errors[] = "Could not get product group name: $productGroupId ($productId)";
                        }
                    } catch (\Exception $e) {
                        logActivity("ezchimp_hook_order: ERROR: get product group ($productGroupId): " . $e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                logActivity("ezchimp_hook_order: ERROR: get product ($productId): " . $e->getMessage());
            }
        }
        if ($ezvars->debug > 0) {
            logActivity("ezchimp_hook_order: clientid - $clientId");
        }
    }
    if ('' != $clientId) {
        $sub = _ezchimp_get_listgroup_subscriptions($clientId, $ezvars);
        if ($ezvars->debug > 0) {
            logActivity("ezchimp_hook_order: sublist2 - " . print_r($sub, true));
        }
        foreach ($productGroupNames as $productGroupName => $one) {
            if ((!empty($groupings[$productGroupName])) || (!empty($groupings1[$productGroupName]))) {
                /* Retrieve client details */
                if (empty($client)) {
                    try {
                        $rows = Capsule::table('tblclients')
                            ->select('firstname', 'lastname', 'email')
                            ->where([
                                [ 'id', '=', $clientId ]
                            ])
                            ->get();
                        if ($rows[0]) {
                            $client = $rows[0];
                        } else {
                            logActivity("ezchimp_hook_order: Could not get client: $clientId");
                        }
                    } catch (\Exception $e) {
                        logActivity("ezchimp_hook_order: ERROR: get client ($clientId): " . $e->getMessage());
                    }
                }
                if ($ezvars->debug > 0) {
                    logActivity("ezchimp_hook_order: client - " . print_r($client, true));
                }
                $email_type = $ezvars->settings['default_format'];
                $firstname = $client->firstname;
                $lastname = $client->lastname;
                $email = $client->email;
                if (!empty($client)) {
                    foreach ($groupings[$productGroupName] as $listId => $list_groupings) {
                        if (!empty($list_groupings)) {
                            if ($ezvars->debug > 0) {
                                logActivity("ezchimp_hook_order: subscription group1 - ".print_r($list_groupings, true));
                            }
                            if (!(is_array($list_groupings))) {
                                if ($ezvars->debug > 0) {
                                    logActivity("ezchimp_hook_order: empty main group");
                                }
                                _ezchimp_update_subscription($clientId, $listId, true, $ezvars);
                                $subscription_groupings1 = [];
                                $subscriptions[] = [ 'list' => $list_groupings, 'grouping' => $subscription_groupings1 ];
                                if ($ezvars->debug > 0) {
                                    logActivity("ezchimp_hook_order: single prodname -". print_r($subscriptions, true));
                                    logActivity("ezchimp_hook_order: listid - ". print_r($list_groupings, true));
                                }
                            } else {
                                foreach ($list_groupings as $mainGroup => $groups) {
                                    foreach ($groups as $group) {
                                        $listStr = $listId.'^:'.$mainGroup.'^:'.$group;
                                        _ezchimp_update_subscription($clientId, $listStr, true, $ezvars);
                                    }
                                    foreach ($sub as $subgrp => $subgrps) {
                                        if (!($subgrps['unsubscribe'] == 1)) {
                                            foreach ($subgrps['grouping'] as $maingroup) {
                                                $subscription_groupings = [];
                                                $groups_str = '';
                                                if ($ezvars->debug > 0) {
                                                    logActivity("ezchimp_hook_order: sub2 - " . print_r($maingroup['groups'], true));
                                                }
                                                if (!(strcmp($mainGroup, $maingroup['name']))) {
                                                    $grps2 = [];
                                                    if (!empty($maingroup['groups'])) {
                                                        $grps1 = explode(',', $maingroup['groups']);
                                                        $grps2 = array_unique($grps1);
                                                        if ($ezvars->debug > 0) {
                                                            logActivity("ezchimp_hook_order: subscriptions grps2 - " . print_r($grps2, true));
                                                        }
                                                    }
                                                    $subscription_groupings[] = [ 'name' => $mainGroup, 'groups' => $grps2 ];
                                                    if ($ezvars->debug > 0) {
                                                        logActivity("ezchimp_hook_order: subscription case1 - " . print_r($mainGroup, true));
                                                    }
                                                    $subscriptions[] = [ 'list' => $subgrps['list'], 'grouping' => $subscription_groupings ];
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        foreach ($subscriptions as $subscription) {
                            _ezchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type, $ezvars);
                        }
                    }
                    foreach($groupings1[$productGroupName] as $listId => $list_unsub){
                        if (!empty($list_unsub)) {
                            if ($ezvars->debug > 0) {
                                logActivity("ezchimp_hook_order: unsubscribe group1 - ".print_r($list_unsub, true));
                            }
                            if (!(is_array($list_unsub))) {
                                _ezchimp_update_subscription($clientId, $listId, false, $ezvars);
                            } else {
                                foreach ($list_unsub as $mainGroup => $groups) {
                                    foreach ($groups as $group) {
                                        $listStr = $listId.'^:'.$mainGroup.'^:'.$group;
                                        _ezchimp_update_subscription($clientId, $listStr, false, $ezvars);
                                    }
                                }
                            }
                        }
                    }
                    foreach ($groupings1[$productGroupName] as $list_id2 => $list_unsub) {
                        $subscription_groupings = [];
                        if (!empty($list_unsub)) {
                            if ($ezvars->debug > 0) {
                                logActivity("ezchimp_hook_order: unsubscribe2 group1 - $list_unsub");
                            }
                            foreach ($list_unsub as $mainGroup => $groups) {
                                $val='';
                                foreach ($sub as $subgrp => $subgrps1) {
                                    foreach ($sub as $subgrp => $subgrps) {
                                        if (!($subgrps['unsubscribe'] == 1)) {
                                            foreach ($subgrps1['grouping'] as $maingroup1) {
                                                if (!(strcmp($mainGroup, $maingroup1['name']))) {
                                                    if ($ezvars->debug > 0) {
                                                        logActivity("ezchimp_hook_order: unsubscribe main1ch1 - " . print_r($mainGroup, true));
                                                    }
                                                    foreach ($subgrps['grouping'] as $maingroup) {
                                                        if (!(strcmp($maingroup1['name'], $maingroup['name']))) {
                                                            $val = true;
                                                            if ($ezvars->debug > 0) {
                                                                logActivity("ezchimp_hook_order: unsubscribe main1ch2 - " . print_r($val, true));
                                                            }
                                                        } else {
                                                            if ($ezvars->debug > 0) {
                                                                logActivity("ezchimp_hook_order: unsubscribe main1ch3 - " . print_r($val, true));
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
                                                $subscription_groupings = [];
                                                $groups_str = '';
                                                if ($ezvars->debug > 0) {
                                                    logActivity("ezchimp_hook_order: unsub2 - " . print_r($maingroup['groups'], true));
                                                }
                                                if (!(strcmp($mainGroup, $maingroup['name']))) {
                                                    $grps2 = [];
                                                    if (!empty($maingroup['groups'])) {
                                                        $grps1 = explode(",", $maingroup['groups']);
                                                        $grps2 = array_unique($grps1);
                                                        if ($ezvars->debug > 0) {
                                                            logActivity("ezchimp_hook_order: grps2 - " . print_r($grps2, true));
                                                        }
                                                    }
                                                    $subscription_groupings[] = [ 'name' => $mainGroup, 'groups' => $grps2 ];
                                                    if ($ezvars->debug > 0) {
                                                        logActivity("ezchimp_hook_order: unsubscribe case1 - " . print_r($mainGroup, true));
                                                    }
                                                    $subscriptions1[] = [ 'list' => $subgrps['list'], 'grouping' => $subscription_groupings ];
                                                }
                                            }
                                        }
                                        if (($subgrps['unsubscribe'] == 1)) {
                                            $subscription_groupings1 = [];
                                            $subscriptions2[] = [ 'list' => $subgrps['list'], 'grouping' => $subscription_groupings1 ];
                                            if ($ezvars->debug > 0) {
                                                logActivity("ezchimp_hook_order: single prodname1 - ". print_r($subscriptions2, true));
                                                logActivity("ezchimp_hook_order: listid - ". print_r($subgrps['list'], true));
                                            }
                                        }
                                    }
                                } else {
                                    $subscription_groupings[] = [ 'name' => $mainGroup, 'groups' => [] ];
                                    if ($ezvars->debug > 0) {
                                        logActivity("ezchimp_hook_order: unsubscribe main1 - " . print_r($mainGroup, true));
                                    }
                                    $subscriptions1[] = [ 'list' => $list_id2, 'grouping' => $subscription_groupings ];
                                }
                            }
                            if (!(is_array($list_unsub))) {
                                $subscription_groupings1 = [];
                                $subscriptions2[] = [ 'list' => $list_unsub, 'grouping' => $subscription_groupings1 ];
                                if ($ezvars->debug > 0) {
                                    logActivity("ezchimp_hook_order: single prodname2 -". print_r($subscriptions2, true));
                                    logActivity("ezchimp_hook_order: listid - ". print_r($list_unsub, true));
                                }
                            }
                        }
                        foreach ($subscriptions2 as $subscription) {
                            _ezchimp_unsubscribe($subscription, $email, $ezvars);
                        }
                        foreach ($subscriptions1 as $subscription) {
                            _ezchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type, $ezvars);
                        }
                    }
                } else {
                    $errors[] = "Could not get client details: " . $clientId;
                }
            } else {
                $errors[] = "Product group [" . $productGroupName . "] lists not found in map";
            }
        }
    }
    if (!empty($errors)) {
        logActivity("ezchimp_hook_order: Errors - " . implode('. ', $errors));
    }
}

function _ezchimp_init_client_subscriptions($clientId, &$ezvars) {
    /* Subscribe to all active lists */
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
            $fieldId = $row->id;
            try {
                $count = Capsule::table('tblcustomfieldsvalues')
                    ->where([
                        [ 'relid', '=', $clientId ],
                        [ 'fieldid', '=', $fieldId ]
                    ])
                    ->count();
                if (0 === $count) {
                    try {
                        Capsule::table('tblcustomfieldsvalues')
                            ->insert([
                                'relid' => $clientId,
                                'fieldid' => $fieldId,
                                'value' => 'on'
                            ]);
                        if ($ezvars->debug > 2) {
                            logActivity("_ezchimp_init_client_subscriptions: subscribed - $clientId, " . $row->fieldoptions);
                        }
                    } catch (\Exception $e) {
                        logActivity("_ezchimp_init_client_subscriptions: ERROR: insert subscription: " . $e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                logActivity("_ezchimp_init_client_subscriptions: ERROR: get subscription count: " . $e->getMessage());
            }
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_init_client_subscriptions: ERROR: get custom field values: " . $e->getMessage());
    }

    $activelists = unserialize($ezvars->settings['activelists']);
    if ($ezvars->debug > 2) {
        logActivity("_ezchimp_init_client_subscriptions: activelists - ".print_r($activelists, true));
    }

    $subscriptions = [];
    foreach ($activelists as $list => $groups) {
        if (!is_array($groups)) {
            $subscriptions[] = [ 'list' => $list ];
        } else {
            $subscription_groupings = [];
            foreach ($groups as $maingroup => $groups) {
                $subscription_groupings[] = [ 'name' => $maingroup, 'groups' => array_keys($groups) ];
            }
            if (!empty($subscription_groupings)) {
                $subscriptions[] = [ 'list' => $list, 'grouping' => $subscription_groupings ];
            }
        }
    }
    if ($ezvars->debug > 1) {
        logActivity("_ezchimp_init_client_subscriptions: subscriptions - ".print_r($subscriptions, true));
    }

    return $subscriptions;
}

function _ezchimp_get_client_subscriptions($client_id, &$ezvars) {
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
        logActivity("_ezchimp_get_client_subscriptions: ERROR: get field options: " . $e->getMessage());
    }
    if ($ezvars->debug > 2) {
        logActivity("_ezchimp_get_client_subscriptions: fields - ".print_r($fields, true));
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
            if ($ezvars->debug > 4) {
                logActivity("_ezchimp_get_client_subscriptions: row - ".print_r($row, true));
            }
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
            logActivity("_ezchimp_get_client_subscriptions: list_groups - ".print_r($list_groups, true));
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_get_client_subscriptions: ERROR: get custom field values: " . $e->getMessage());
    }

    $activelists = unserialize($ezvars->settings['activelists']);
    if ($ezvars->debug > 2) {
        logActivity("_ezchimp_get_client_subscriptions: activelists - ".print_r($activelists, true));
    }
    $subscriptions = [];
    foreach ($list_groups as $list => $groups) {
        unset($activelists[$list]);
        if (empty($groups)) {
            $subscriptions[] = [ 'list' => $list ];
        } else {
            $subscription_groupings = [];
            foreach ($groups as $mainGroup => $subGroups) {
                $subscription_groupings[] = [ 'name' => $mainGroup, 'groups' => $subGroups ];
            }
            if (!empty($subscription_groupings)) {
                $subscriptions[] = ['list' => $list, 'grouping' => $subscription_groupings];
            }
        }
    }
    // Unsubscribe from remaining active lists
    foreach ($activelists as $list => $groups) {
        $subscriptions[] = [ 'list' => $list, 'unsubscribe' => 1 ];
    }
    if ($ezvars->debug > 1) {
        logActivity("_ezchimp_get_client_subscriptions: subscriptions - ".print_r($subscriptions, true));
    }
    return $subscriptions;
}

function _ezchimp_get_listgroup_subscriptions($client_id, &$ezvars) {
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
        logActivity("_ezchimp_get_listgroup_subscriptions: ERROR: get field options: " . $e->getMessage());
    }
    if ($ezvars->debug > 2) {
        logActivity("_ezchimp_get_listgroup_subscriptions: fields - ".print_r($fields, true));
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
            logActivity("_ezchimp_get_listgroup_subscriptions: list_groups - ".print_r($list_groups, true));
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_get_listgroup_subscriptions: ERROR: get custom field values: " . $e->getMessage());
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
        logActivity("_ezchimp_get_listgroup_subscriptions: all_lists - ".print_r($all_lists, true));
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
                logActivity("_ezchimp_get_listgroup_subscriptions: all_groups - ".print_r($all_groups, true));
            }
            if ($ezvars->debug > 2) {
                logActivity("_ezchimp_get_listgroup_subscriptions: interest_groups - ".print_r($groupings, true));
            }
            foreach ($groups as $maingroup => $groups) {
                unset($all_groups[$maingroup]);
                $subscription_groupings[] = [ 'name' => $maingroup, 'groups' => $groups ];
            }
            foreach ($all_groups as $gr) {
                if ($ezvars->debug > 2) {
                    logActivity("_ezchimp_get_listgroup_subscriptions: all_groups2 - ".print_r($gr, true));
                }
            }
            if (!empty($subscription_groupings)) {
                $subscriptions[] = [ 'list' => $list, 'grouping' => $subscription_groupings ];
            }
        }
    }
    if ($ezvars->debug > 3) {
        logActivity("_ezchimp_get_listgroup_subscriptions: remaining all_lists - ".print_r($all_lists, true));
    }
    foreach ($all_lists as $list => $one) {
        $subscriptions[] = [ 'list' => $list, 'unsubscribe' => 1 ];
    }
    if ($ezvars->debug > 1) {
        logActivity("_ezchimp_get_listgroup_subscriptions: subscriptions - ".print_r($subscriptions, true));
    }

    return $subscriptions;
}

function _ezchimp_get_client_email_type($clientId, &$ezvars) {
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
    } catch (\Exception $e) {
        logActivity("_ezchimp_get_client_email_type: ERROR: get default email format custom field ID: " . $e->getMessage());
    }
    if ($ezvars->debug > 1) {
        logActivity("_ezchimp_get_client_email_type: field_id - $fieldId");
    }

    if ($fieldId > 0) {
        try {
            $rows = Capsule::table('tblcustomfieldsvalues')
                ->select('value')
                ->where([
                    [ 'fieldid', '=', $fieldId ],
                    [ 'relid', '=', $clientId ]
                ])
                ->get();
            if ($rows[0]) {
                $emailType = strtolower($rows[0]->value);
            }
        } catch (\Exception $e) {
            logActivity("_ezchimp_get_client_email_type: ERROR: get default email format custom field value: " . $e->getMessage());
        }
    }

    if ($ezvars->debug > 2) {
        logActivity("_ezchimp_get_client_email_type: email_type - $emailType");
    }

    return $emailType;
}

function _ezchimp_get_client_subscribe_contacts($clientId, &$ezvars) {
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
            logActivity("_ezchimp_get_client_subscribe_contacts: field_id - $fieldId");
        }
    } catch (\Exception $e) {
        logActivity("_ezchimp_get_client_subscribe_contacts: ERROR: get field ID: " . $e->getMessage());
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
                logActivity("_ezchimp_get_client_subscribe_contacts: subscribecontacts - $subscribeContacts");
            }
        } catch (\Exception $e) {
            logActivity("_ezchimp_get_client_subscribe_contacts: ERROR: get field options: " . $e->getMessage());
        }
    }

    return $subscribeContacts;
}

function ezchimp_hook_client_add($vars) {
    $ezvars = new EzchimpVars();
    _ezchimp_hook_init($ezvars);

    if ($ezvars->debug > 0) {
        logActivity("ezchimp_hook_client_add: vars - ".print_r($vars, true));
    }

    $client_id = $vars['userid'];
    $firstname = $vars['firstname'];
    $lastname = $vars['lastname'];
    $email = $vars['email'];

    if ('on' == $ezvars->settings['showorder']) {
        $subscriptions = _ezchimp_get_client_subscriptions($client_id, $ezvars);
    } else if ('on' == $ezvars->settings['default_subscribe']) {
        $subscriptions = _ezchimp_init_client_subscriptions($client_id, $ezvars);
    }

    $email_type = _ezchimp_get_client_email_type($client_id, $ezvars);
    foreach ($subscriptions as $subscription) {
        if (isset($subscription['unsubscribe'])) {
                _ezchimp_unsubscribe($subscription, $email, $ezvars);
            if ($ezvars->debug > 1) {
                logActivity("ezchimp_hook_client_add: unsubscribe - $email, ".$subscription['list']);
            }
        } else {
                _ezchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type, $ezvars);
            if ($ezvars->debug > 1) {
                logActivity("ezchimp_hook_client_add: subscribe - $email, ".print_r($subscription, true));
            }
        }
    }
}

function ezchimp_hook_client_edit($vars) {
    $ezvars = new EzchimpVars();
    _ezchimp_hook_init($ezvars);

    if ($ezvars->debug > 0) {
        logActivity("ezchimp_hook_client_edit: vars - ".print_r($vars, true));
    }

    $clientId = $vars['userid'];
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
        try {
            $rows = Capsule::table('tblclients')
                ->select('firstname', 'lastname')
                ->where([
                    [ 'id', '=', $clientId ]
                ])
                ->get();
            if ($rows[0]) {
                $firstname = $rows[0]->firstname;
                $lastname = $rows[0]->lastname;
            } else {
                $firstname = '';
                $lastname = '';
            }
        } catch (\Exception $e) {
            logActivity("ezchimp_hook_client_edit: ERROR: get client ($clientId): " . $e->getMessage());
        }
    }

    $subscriptions = _ezchimp_get_client_subscriptions($clientId, $ezvars);
    $email_type = _ezchimp_get_client_email_type($clientId, $ezvars);

    foreach ($subscriptions as $subscription) {
        if (isset($subscription['unsubscribe']) || ('Active' != $status)) {
                _ezchimp_unsubscribe($subscription, $email, $ezvars);
            if ($ezvars->debug > 1) {
                logActivity("ezchimp_hook_client_edit: unsubscribe - $email, ".$subscription['list']);
            }
        } else {
                _ezchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type, $ezvars);
            if ($ezvars->debug > 1) {
                logActivity("ezchimp_hook_client_edit: subscribe - $email, ".print_r($subscription, true));
            }
        }
    }

    $contacts = [];
    try {
        $contacts = Capsule::table('tblcontacts')
            ->select('firstname', 'lastname', 'email')
            ->where([
                [ 'userid', '=', $clientId ]
            ])
            ->get();
    } catch (\Exception $e) {
        logActivity("ezchimp_hook_client_edit: ERROR: get client contacts: " . $e->getMessage());
    }
    if (!empty($contacts)) {
        if (('on' == $ezvars->settings['subscribe_contacts']) && ('on' == _ezchimp_get_client_subscribe_contacts($clientId, $ezvars)) && ('Active' == $status)) {
            foreach ($contacts as $contact) {
                foreach ($subscriptions as $subscription) {
                    if ($contact->email != $email) {
                        if (isset($subscription['unsubscribe'])) {
                            _ezchimp_unsubscribe($subscription, $contact->email, $ezvars);
                            if ($ezvars->debug > 1) {
                                logActivity("ezchimp_hook_client_edit: unsubscribe contact - " . $contact->email . ", " . $subscription['list']);
                            }
                        } else {
                            _ezchimp_subscribe($subscription, $contact->firstname, $contact->lastname, $contact->email, $email_type, $ezvars);
                            if ($ezvars->debug > 1) {
                                logActivity("ezchimp_hook_client_edit: subscribe contact - " . $contact->email . ", " . print_r($subscription, true));
                            }
                        }
                    }
                }
            }
        } else {
            foreach ($contacts as $contact) {
                if ($contact->email != $email) {
                    foreach ($subscriptions as $subscription) {
                        _ezchimp_unsubscribe($subscription, $contact->email, $ezvars);
                        if ($ezvars->debug > 1) {
                            logActivity("ezchimp_hook_client_edit: unsubscribe contact - " . $contact->email . ", " . $subscription['list']);
                        }
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
    $ezvars = new EzchimpVars();
    _ezchimp_hook_init($ezvars);

    if ($ezvars->debug > 0) {
        logActivity("ezchimp_hook_client_delete: vars - ".print_r($vars, true));
    }
}

function ezchimp_hook_contact_subscribe($vars) {
    $ezvars = new EzchimpVars();
    _ezchimp_hook_init($ezvars);

    if ($ezvars->debug > 0) {
        logActivity("ezchimp_hook_contact_subscribe: vars - ".print_r($vars, true));
    }

    $clientId = $vars['userid'];
    $firstname = $vars['firstname'];
    $lastname = $vars['lastname'];
    $email = $vars['email'];

    if (('on' == $ezvars->settings['subscribe_contacts']) && ('on' == _ezchimp_get_client_subscribe_contacts($clientId, $ezvars))) {
        $subscriptions = _ezchimp_get_client_subscriptions($clientId, $ezvars);
        $email_type = _ezchimp_get_client_email_type($clientId, $ezvars);
        foreach ($subscriptions as $subscription) {
            _ezchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type, $ezvars);
            if ($ezvars->debug > 1) {
                logActivity("ezchimp_hook_contact_subscribe: subscribe contact - $email, ".print_r($subscription, true));
            }
        }
    }
}

/**
 * Function for WHMCS AffiliateActivation hook
 * Mailing list subscription for affiliates
 *
 * @param array
 */
function ezchimp_hook_affiliate_subscribe($vars) {
    $ezvars = new EzchimpVars();
    _ezchimp_hook_init($ezvars);

    if ($ezvars->debug > 0) {
        logActivity("ezchimp_hook_affiliate_subscribe: vars - ".print_r($vars, true));
    }

    $clientId = $vars['userid'];
    $continue = false;
    try {
        $rows = Capsule::table('tblclients')
            ->select('firstname', 'lastname', 'email')
            ->where([
                [ 'id', '=', $clientId ]
            ])
            ->get();
        if ($rows[0]) {
            $firstname = $rows[0]->firstname;
            $lastname = $rows[0]->lastname;
            $email = $rows[0]->email;
            $continue = true;
        } else {
            logActivity("ezchimp_hook_affiliate_subscribe: ERROR: Could not fetch client details");
        }
    } catch (\Exception $e) {
        logActivity("ezchimp_hook_affiliate_subscribe: ERROR: get client ($clientId): " . $e->getMessage());
    }

    if ($continue) {
        // build subscriptions based on affilatelists in settings and subscribe the client to those lists and interest groups
        // if the new list and interest group is present for the client enable it in corresponding custom field as well
        $subscriptions = [];
        $affilatelists = unserialize($ezvars->settings['affilatelists']);
        foreach ($affilatelists as $listId => $groups) {
            if (empty($groups)) {
                $subscriptions[] = [ 'list' => $listId ];
                _ezchimp_update_subscription($clientId, $listId, true, $ezvars);
            } else {
                $subscription_groupings = [];
                $all_groups = [];
                $params['id'] = $listId;
                $groupings = _ezchimp_listInterestGroupings($params, $ezvars);
                if (!empty($groupings['groupings'])) {
                    foreach ($groupings['groupings'] as $grouping) {
                        $all_groups[$grouping] = 1;
                    }
                }
                if ($ezvars->debug > 2) {
                    logActivity("ezchimp_hook_affiliate_subscribe: all_groups - ".print_r($all_groups, true));
                }
                foreach ($groups as $mainGroup => $sub_groups) {
                    unset($all_groups[$mainGroup]);
                    foreach ($sub_groups as $group => $subscribed) {
                        $listStr = $listId.'^:'.$mainGroup.'^:'.$group;
                        _ezchimp_update_subscription($clientId, $listStr, true, $ezvars);
                    }
                    $subscription_groupings[] = [ 'name' => $mainGroup, 'groups' => array_keys($sub_groups) ];
                }
                if (!empty($all_groups)) {
                    foreach ($all_groups as $mainGroup => $one) {
                        $subscription_groupings[] = [ 'name' => $mainGroup, 'groups' => [] ];
                        if ($ezvars->debug > 3) {
                            logActivity("ezchimp_hook_affiliate_subscribe: empty main group - $mainGroup");
                        }
                    }
                }
                if (!empty($subscription_groupings)) {
                    $subscriptions[] = [ 'list' => $listId, 'grouping' => $subscription_groupings ];
                }
            }
        }
        $email_type = _ezchimp_get_client_email_type($clientId, $ezvars);
        foreach ($subscriptions as $subscription) {
            _ezchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type, $ezvars);
            if ($ezvars->debug > 1) {
                logActivity("ezchimp_hook_affiliate_subscribe: email - $email, " . print_r($subscription, true));
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
add_hook("AffiliateActivation",1,"ezchimp_hook_affiliate_subscribe");

