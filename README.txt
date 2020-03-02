IMPORTANT
---------

We are looking for maintainers for this module. If interested, please request via github.


SETUP
-----
1. Download and extract the ezchimp_X.xx.zip file into your modules/addons/ folder within your WHMCS directory.

2. Move the ezchimp_webhook.php file to your WHMCS root directory (eg: /home/username/public_html/whmcs).
 Test and make sure http://yourdomain.com/whmcs/ezchimp_webhook.php is giving a blank page instead of any
 errors such as internal server error (500). If it does, check and fix the ownership (should be owned by
 the domain’s username on a cpanel server for example), permission (try 555) etc so that it works.

3. Go to WHMCS admin -> Setup -> Addon Modules and “activate” the module listed as “MailChimp newsletter”

4. Set the WHMCS base URL (eg: http://yourdomain.com/whmcs, since version 1.6 only).

5. After successful activation enter you MailChimp API key, set access control below in the same page.

6. Go to “Addons” -> “MailChimp newsletter” and configure the settings as necessary.

7. Click on “Lists & Groups” in right menu to display all mailing lists and interest groups in your Mailchimp account.

8. Enable those you need to make available to your clients in WHMCS. Give an alias as you wish.
 If an alias is not specified, the name of the interest group (or the name of the list if there
 are no interest groups in it) will be used.

9. The “Status” link in side menu will display the subscription status of your clients and their sub-contacts
 to mailing lists and interest groups.

10. Use the “Tools” link to initially subscribe existing clients after a fresh activation of ezchimp module.

VERSION NOTES:
- Ezchimp 3.x versions doesn't support WHMCS 6.x
- Ezchimp 1.x and 2.x versions have been discontinued and will not work because MailChimp has stopped supporting
  their API version 1.3 which is used in those versions.

See the full documentation with screenshots here: http://supportmonk.com/ezchimp-whmcs-mailchimp-integration/


LICENSE
-------
ezchimp source code is licensed under GPLv3 which can be found here: http://www.gnu.org/licenses/gpl.html


CREDITS:
-------
* AdMod Technologies Pvt Ltd. (www.admod.com, www.ezeelogin.com, www.supportmonk.com) - Developed this ezchimp module


CHANGE LOG:
----------
3.1 - 28 Dec 2018: Fix sending firstname and lastname to Mailchimp.
3.0 - 27 Aug 2018: Update MailChimp API to version 3.0. German language translation.
2.7 - 19 Aug 2017: Fix a bug in auto-subscribe by product groups.
2.6 - 21 Jun 2017: New tool to subscribe inactive clients as well.
2.5 - 19 Jun 2017: Tools to subscribe only the clients who have not opted out of marketing emails.
2.4 - 21 Mar 2017: Subscribe affiliate to selected lists on activation.
2.3 - 17 Mar 2017: Fix interest group subscriptions within the same list.
2.2 - 01 Mar 2017: Do not un-subscribe from lists that aren't enabled.
2.1 - 15 Feb 2017: Fix auto subscribe settings.
2.0 - 30 Dec 2016: Use Capsule based database interaction to support WHMCS 7.x with PHP 7.x
1.22 - Fix interest group subscriptions within the same list.
1.21 - Do not un-subscribe from lists that aren't enabled.
1.20 - 16 Nov 2016: Fix issue with default subscription when adding client.
1.19 - 17 Aug 2016: New tool to subscribe existing clients with an active hosting account who are not already subscribed to any list/group to all active lists/groups.
1.18 - 24 Dec 2014: Improve debug messages when listing existing subscriptions
1.17 - 05 Aug 2014: Tool to reset subscriptions as per auto-subscribe settings
1.16 - 27 Mar 2014: Remove global variables to improve security
