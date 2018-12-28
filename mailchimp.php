<?php
/**
 * Function for calling MailChimp API
 *
 * @param string
 * @param array
 * @param object
 */
function _ezchimp_mailchimp_api($method, $params, &$ezvars) {
	if ($ezvars->debug > 4) {
		logActivity("_ezchimp_mailchimp_api: method - $method, params - " . print_r($params, 1));
	}
    $apikey = $params['apikey'];
	$parts = explode('-', $apikey);
	$dc = $parts[1];
    $apiroot = "https://$dc.api.mailchimp.com/3.0/";
    $request_method = "GET";
    $data = [];
    if (!empty($params['href'])) {
        $url = $params['href'];
        if ($ezvars->debug > 3) {
            logActivity("_ezchimp_mailchimp_api: url1 - $url");
        }
    } else {
        $url = $apiroot;
        if ($ezvars->debug > 3) {
            logActivity("_ezchimp_mailchimp_api: url2 - $url");
        }
        switch ($method) {
            case 'listMemberInfo':
                $url .= "lists/" . $params['id'] . "/members/" . md5(strtolower($params['email_address']));
                break;
            case 'listInterestCategories':
                $url .= "lists/" . $params['id'] . "/interest-categories";
                break;
            case 'listInterestCategoryInterests':
                $url .= "lists/" . $params['id'] . "/interest-categories/" . $params['category_id'] . "/interests";
                break;
            case 'listWebhooks':
                $url .= "lists/" . $params['id'] . "/webhooks";
                break;
            case 'listWebhookDel':
                $url .= "lists/" . $params['id'] . "/webhooks/" . $parms['webhook_id'];
                $request_method = "DELETE";
                break;
            case 'listWebhookAdd':
                $url .= "lists/" . $params['id'] . "/webhooks";
                $request_method = "POST";
                $data = $params['webhook'];
                break;
            case 'listMemberDelete':
                $url .= "lists/" . $params['id'] . "/members/" . md5(strtolower($params['email_address']));
                $request_method = "DELETE";
                break;
            case 'listMemberCreate':
                $url .= "lists/" . $params['id'] . "/members";
                $request_method = "POST";
                $data = $params['member'];
                $data['status'] = "subscribed";
                break;
            case 'listMemberUpdate':
                $url .= "lists/" . $params['id'] . "/members/" . md5(strtolower($params['email_address']));
                $request_method = "PATCH";
                $data = $params['member'];
                $data['status'] = "subscribed";
                break;
            case 'listUnsubscribe':
                $url .= "lists/" . $params['id'] . "/members/" . md5(strtolower($params['email_address']));
                $request_method = "PATCH";
                $data['status'] = "unsubscribed";
                break;
            case 'listSubscribe':
                $url .= "lists/" . $params['id'] . "/members/" . md5(strtolower($params['email_address']));
                $request_method = "PUT";
                $data = $params['member'];
                $data['status'] = "subscribed";
                $data['status_if_new'] = "subscribed";
                break;
            default:
                $url .= $method;
                break;
        }
        if ($ezvars->debug > 3) {
            logActivity("_ezchimp_mailchimp_api: url3 - $url");
        }
    }
    if ($params['query']) {
        $url .= "?" . http_build_query($params['query']);
        if ($ezvars->debug > 3) {
            logActivity("_ezchimp_mailchimp_api: url4 - $url");
        }
    }

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HTTPHEADER, ['content-type: application/json']);
	curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_USERAGENT, "ezchimp");
	curl_setopt($ch, CURLOPT_TIMEOUT, 100); /* seconds */
    curl_setopt($ch, CURLOPT_USERPWD, "anystring:$apikey");
    if ("POST" === $request_method) {
        curl_setopt($ch, CURLOPT_POST, true);
    } else if ("PUT" === $request_method) {
        curl_setopt($ch, CURLOPT_PUT, true);
    } else if ("PATCH" === $request_method || "DELETE" === $request_method) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_method);
    }
    if (!empty($data)) {
        $data = json_encode($data, JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
	$retval = curl_exec($ch);
    if (false === $retval && $ezvars->debug > 0) {
        logActivity("_ezchimp_mailchimp_api: ERROR - " . curl_error($ch));
    }
	curl_close($ch);

	if ($ezvars->debug > 4) {
		logActivity("_ezchimp_mailchimp_api: URL - $url, Request - $request_method, Payload - $data, Response - " . htmlentities($retval));
	}
	return false === $retval ? false : json_decode($retval);
}

function _ezchimp_lists($params, &$ezvars) {
    $params['query'] = ['count' => 50, 'fields' => ['lists.id', 'lists.name']];
    $result = _ezchimp_mailchimp_api('lists', $params, $ezvars);
	if ($ezvars->debug > 2) {
		logActivity("_ezchimp_lists: result:" . print_r($result, 1));
	}
    return $result->lists;
}

function _ezchimp_listMemberInfo($params, &$ezvars) {
    $members = [];
    $params['query'] = [
        'fields' => [
            'email_address',
            'email_type',
            'status',
            'member_rating',
            'interests'
        ]
    ];
    foreach ($params['email_addresses'] as $email_address) {
        $params['email_address'] = $email_address;
        $result = _ezchimp_mailchimp_api('listMemberInfo', $params, $ezvars);
        if ($result && $result->email_address) {
            $members[] = $result;
        }
    }
	if ($ezvars->debug > 3) {
		logActivity("_ezchimp_listMemberInfo: members:" . print_r($members, 1));
	}
    return $members;
}

function _ezchimp_listInterestGroupings($params, &$ezvars) {
    $groupings = [];
    $interestmap = [];
    $params['query'] = ['count' => 50, 'fields' => ['categories.id', 'categories.title']];
    $result = _ezchimp_mailchimp_api('listInterestCategories', $params, $ezvars);
    if ($ezvars->debug > 3) {
        logActivity("_ezchimp_listInterestGroupings: categories: " . print_r($result, 1));
    }
    if ($result->categories) {
        $params['query'] = ['fields' => ['interests.id', 'interests.name']];
        foreach ($result->categories as $category) {
            if ($ezvars->debug > 2) {
                logActivity("_ezchimp_listInterestGroupings: category - " . $category->title);
            }
            $groupings[$category->title] = [];
            $interestmap[$category->title] = $category->id;
            $params['category_id'] = $category->id;
            $result2 = _ezchimp_mailchimp_api('listInterestCategoryInterests', $params, $ezvars);
            if ($ezvars->debug > 3) {
                logActivity("_ezchimp_listInterestGroupings: category:" . $category->title . ", interests: " . print_r($result2, 1));
            }
            if ($result2->interests) {
                foreach ($result2->interests as $interest) {
                    if ($ezvars->debug > 2) {
                        logActivity("_ezchimp_listInterestGroupings: category - " . $category->title . ", interest - " . $interest->name);
                    }
                    $groupings[$category->title][] = $interest->name;
                    $interestmap[$category->title . '^:' . $interest->name] = $interest->id;
                }
            }
        }
    }
	if ($ezvars->debug > 1) {
		logActivity("_ezchimp_listInterestGroupings: groupings:" . print_r($groupings, 1));
		logActivity("_ezchimp_listInterestGroupings: interestmap:" . print_r($interestmap, 1));
	}
    return ['groupings' => $groupings, 'interestmap' => $interestmap];
}

function _ezchimp_listWebhookDel($params, &$ezvars) {
    $params['query'] = ['fields' => ['webhooks.id', 'webhooks.url']];
    $result = _ezchimp_mailchimp_api('listWebhooks', $params, $ezvars);
    logActivity("_ezchimp_listWebhookDel: webhooks: " . print_r($result, 1));
    if ($result->webhooks) {
        foreach ($result->webhooks as $webhook) {
            if ($webhook->url === $params['url']) {
                $params['webhook_id'] = $webhook->id;
                _ezchimp_mailchimp_api('listWebhookDel', $params);
                break;
            }
        }
    }
}

function _ezchimp_listWebhookAdd($params, &$ezvars) {
    $result = _ezchimp_mailchimp_api('listWebhookAdd', $params, $ezvars);
	if ($ezvars->debug > 2) {
		logActivity("_ezchimp_listWebhookAdd: result:" . print_r($result, 1));
	}
    return $result->id ? $result->id : false;
}

function _ezchimp_listUnsubscribe($params, &$ezvars) {
    if ($params['delete_member']) {
        $result = _ezchimp_mailchimp_api('listMemberDelete', $params, $ezvars);
    } else {
        $result = _ezchimp_mailchimp_api('listUnsubscribe', $params, $ezvars);
    }
	if ($ezvars->debug > 2) {
		logActivity("_ezchimp_listUnsubscribe: result:" . print_r($result, 1));
	}
}

function _ezchimp_listSubscribe($params, &$ezvars) {
    // Currently update or insert using PUT is not working with MailChimp API version 3.0
    // $result = _ezchimp_mailchimp_api('listSubscribe', $params, $ezvars);
    $params['email_address'] = $params['member']['email_address'];
    $params['query'] = ['fields' => ['id']];
    $exist_result = _ezchimp_mailchimp_api('listMemberInfo', $params, $ezvars);
    if ($exist_result && $exist_result->id) {
        $result = _ezchimp_mailchimp_api('listMemberUpdate', $params, $ezvars);
    } else {
        $result = _ezchimp_mailchimp_api('listMemberCreate', $params, $ezvars);
    }
    return $result;
}

function _ezchimp_unsubscribe($subscription, $email, &$ezvars) {
    $params = [
        'id' => $subscription['list'],
        'email_address' => $email,
        'apikey' => $ezvars->config['apikey'],
        'delete_member' => (isset($ezvars->settings['delete_member']) && ('on' == $ezvars->settings['delete_member'])) ? true : false
    ];
    _ezchimp_listUnsubscribe($params, $ezvars);
}

function _ezchimp_subscribe($subscription, $firstname, $lastname, $email, $email_type='html', &$ezvars) {
	$merge_vars = [
		'FNAME' => $firstname,
		'LNAME' => $lastname
    ];
	$params = [
        'id' => $subscription['list'],
        'apikey' => $ezvars->config['apikey'],
        'member' => [
            'email_address' => $email,
            'email_type' => $email_type,
            'merge_fields' => $merge_vars
        ]
    ];
	if (!empty($subscription['grouping'])) {
        $interestmaps = unserialize($ezvars->settings['interestmaps']);
        $interestmap = $interestmaps[$subscription['list']];
        if ($ezvars->debug > 2) {
            logActivity("_ezchimp_subscribe: interestmaps:" . print_r($interestmaps, 1));
            logActivity("_ezchimp_subscribe: interestmap:" . print_r($interestmap, 1));
            logActivity("_ezchimp_subscribe: grouping:" . print_r($subscription['grouping'], 1));
        }
        $subscribed_map = [];
		$interests = [];
        foreach ($subscription['grouping'] as $maingroup) {
            $interestMapKey = $maingroup['name'];
            $subscribed_map[$interestMapKey] = 1;
            if (empty($maingroup['groups'])) {
                $category_id = $interestmap ? $interestmap[$interestMapKey] : 0;
                if ($category_id) {
                    $interests[$category_id] = true;
                } else {
                    logActivity("_ezchimp_subscribe: error: cannot find interest category ID - " . $interestMapKey);
                }
            } else {
                foreach ($maingroup['groups'] as $group) {
                    $interestMapKey = $maingroup['name'] . '^:' . $group;
                    $subscribed_map[$interestMapKey] = 1;
                    $interest_id = $interestmap ? $interestmap[$interestMapKey] : 0;
                    if ($interest_id) {
                        $interests[$interest_id] = true;
                    } else {
                        logActivity("_ezchimp_subscribe: error: cannot find interest ID - " . $interestMapKey);
                    }
                }
            }
        }
        // unsubscribe the rest
        foreach ($interestmap as $key => $id) {
            if (!isset($subscribed_map[$key])) {
                $interests[$id] = false;
            }
        }
        if (!empty($interests)) {
            $params['member']['interests'] = $interests;
        }
	}
	_ezchimp_listSubscribe($params, $ezvars);
}