=== Invalid Traffic Blocker ===
Contributors: maocular
Tags: invalid traffic, blocker, ip, adsense, vpn
Requires at least: 4.5
Tested up to: 6.8
Stable tag: 1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 7.2
Text Domain: invalid-traffic-blocker

Protect your site from invalid traffic by blocking suspicious IPs using the IPHub.info API.

== Description ==
Invalid Traffic Blocker is a WordPress plugin that uses the IPHub.info API to detect and block unwanted traffic such as bots, VPNs, and suspicious IP addresses. This helps AdSense publishers and website owners ensure that only valid traffic is served. This is not an official plugin for IPHub.info.

== Installation ==
1. Upload the plugin files to the `/wp-content/plugins/invalid-traffic-blocker` directory or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to `Settings > Invalid Traffic Blocker` in your WordPress admin dashboard.
4. Enter your IPHub API key, choose your desired blocking mode, and add any trusted IP addresses to the whitelist.
5. Save your settings and use the "Test API Connectivity" and "Whitelist My IP" buttons as needed.

== Frequently Asked Questions ==
= What is Invalid Traffic Blocker? =
Invalid Traffic Blocker is a WordPress plugin that uses the IPHub.info API to detect and block unwanted traffic, including bots, VPNs, and suspicious IP addresses. It helps ensure that only valid traffic reaches your website.

= How do I configure the plugin? =
After activating the plugin, go to `Settings > Invalid Traffic Blocker`. Here you can enter your API key, select a blocking mode, and manage your IP whitelist.

= What if my API key is missing or invalid? =
If the API key is missing or incorrect, the plugin will not perform any blocking. Make sure to obtain a valid API key from [IPHub.info](https://iphub.info/register).

= Can I update the whitelist later? =
Yes, you can update the list of whitelisted IP addresses at any time from the settings page.

= How do I whitelist my IP? =
Click the "Whitelist My IP" button on the settings page to add your current IP address to the whitelist.

== Changelog ==
= 1.3 =
* Added “Allow Known Crawlers” setting to automatically bypass IP checks for common search engine bots (Googlebot, Bingbot, Slurp, DuckDuckBot, Baiduspider, YandexBot).
* Introduced “Additional Crawler Patterns” textarea so admins can specify extra User-Agent regexes to whitelist.
* Updated `invatrbl_check_visitor_ip()` to use `filter_input()` and `sanitize_text_field()` when reading `$_SERVER['HTTP_USER_AGENT']` to comply with WP security standards.
* Ensured User-Agent checks are fully sanitized to eliminate any `InputNotSanitized` warnings during plugin review.
* Streamlined front-end blocking logic so known crawlers (built-in or custom) are skipped before performing IPHub API lookups.
* Minor code refactoring and cleanup to align with WordPress Plugin Coding Standards.

= 1.2 =
* Use admin’s current IP for API testing instead of a default.
* Fancy info boxes for API test responses (green for success, red for errors).
* "Whitelist My IP" button to add the admin's current IP to the whitelist automatically.

= 1.1 =
* added whitelist functionality and basic API connectivity testing.
* introduced multiple blocking modes.

= 1.0 =
* Initial release with core functionality:
  - Integration with IPHub.info API.
  - Multiple blocking modes: Safe, Strict, and Custom.
  - Persistent warning message for blocked users.
  - Basic security measures and caching implementation.


== Upgrade Notice ==
= 1.3 =
This update adds an option to allow known search engine crawlers and custom User-Agent patterns to bypass the IP check, and ensures full sanitization of the User-Agent header to meet WordPress security requirements.

= 1.2 =
This update includes improved API testing with admin IP detection, styled response messages, and an automatic whitelist option.

== Screenshots ==
1. Admin settings page with API key, blocking mode options, and whitelist functionality.
2. API connectivity test output showing a green info box on success.
3. Warning message for blocked users.

== External Services ==
This plugin connects to the IPHub.info API to validate IP addresses and determine whether they are suspicious, likely belonging to bots, VPNs, or invalid traffic.
Data transmitted:
  • The visitor's IP address is sent to IPHub.info each time a check is performed.
Purpose:
  • To determine the nature of the IP (e.g., non‑residential, residential suspicious) and decide whether to block access.
Terms and Privacy:
  • IPHub.info Terms of Service: https://iphub.info/legal/tos
  • IPHub.info Privacy Policy: https://iphub.info/legal/privacy
