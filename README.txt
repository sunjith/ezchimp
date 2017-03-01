SETUP
-------
1. Download and extract the ezchimp.zip file into your modules/addons/ folder within your WHMCS directory.

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

See the full documentation with screenshots here: http://blog.admod.com/2012/01/23/ezchimp-whmcs-mailchimp-integration/


LICENSE
-------

ezchimp source code is licensed under GPLv3 which can be found here: http://www.gnu.org/licenses/gpl.html


Notes:
-----
We understand the importance of open source especially for a third party module going into a billing
system. We have nothing to hide and decided to open source ezchimp. You are free to go through the code,
make corrections or enhancements and share it back. Or maybe this will help someone as a reference to
build another similar WHMCS module or MailChimp integration for another software like WHMCS.

Please send your fixes, updates, modifications (as patch if possible), suggestions and ideas to ezchimp@admod.com
to be included in the distribution so that everyone can benefit from it. And it would be easy for you to
follow ezchimp updates as you won't have to apply your modifications to each later release of ezchimp.
Also we shall include your name and website URL (if any) in CREDITS section below.


CREDITS:
-------
* AdMod Technologies Pvt Ltd. (www.admod.com, www.ezeelogin.com, www.supportmonk.com) - Develop, maintain and support ezchimp module


CHANGE LOG:
----------
1.21 - Do not un-subscribe from lists that aren't enabled.
1.20 - 16 Nov 2016: Fix issue with default subscription when adding client.
1.19 - 17 Aug 2016: New tool to subscribe existing clients with an active hosting account who are not already subscribed to any list/group to all active lists/groups.
1.18 - 24 Dec 2014: Improve debug messages when listing existing subscriptions
1.17 - 05 Aug 2014: Tool to reset subscriptions as per auto-subscribe settings
1.16 - 27 Mar 2014: Remove global variables to improve security
