# Invalid Traffic Blocker

**Contributors:** maocular  
**Tags:** invalid traffic, blocker, ip, adsense, vpn  
**Requires at least:** 4.5  
**Tested up to:** 7.1  
**Stable tag:** 1.5.1  
**License:** GPLv2 or later  
**License URI:** [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)  
**Requires PHP:** 7.2  
**Text Domain:** invalid-traffic-blocker  

## Short Description

Protect your site from invalid traffic by blocking suspicious IPs using the IPHub.info API.

## Description

Invalid Traffic Blocker is a WordPress plugin that uses the IPHub.info API to detect and block unwanted traffic such as bots, VPNs, and suspicious IP addresses. This helps AdSense publishers and website owners ensure that only valid traffic is served. This is not an official plugin for IPHub.info.

This plugin is complete and free to use, with no locked features. If you also run sites that aren't built on WordPress, [IVTB](https://ivtb.uk/) is the enterprise version of Invalid Traffic Blocker for other platforms.

## Installation

1. Upload the plugin files to the `/wp-content/plugins/invalid-traffic-blocker` directory or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to `Settings > Invalid Traffic Blocker` in your WordPress admin dashboard.
4. Enter your IPHub API key, choose your desired blocking mode, and add any trusted IP addresses to the whitelist.
5. Save your settings and use the "Test API Connectivity" and "Whitelist My IP" buttons as needed.

## Frequently Asked Questions

### What is Invalid Traffic Blocker?

Invalid Traffic Blocker is a WordPress plugin that uses the IPHub.info API to detect and block unwanted traffic, including bots, VPNs, and suspicious IP addresses. It helps ensure that only valid traffic reaches your website.

### How do I configure the plugin?

After activating the plugin, go to `Settings > Invalid Traffic Blocker`. Here you can enter your API key, select a blocking mode, and manage your IP whitelist.

### What if my API key is missing or invalid?

If the API key is missing or incorrect, the plugin will not perform any blocking. Make sure to obtain a valid API key from [IPHub.info](https://iphub.info/register).

### Can I update the whitelist later?

Yes, you can update the list of whitelisted IP addresses at any time from the settings page.

### How do I whitelist my IP?

Click the "Whitelist My IP" button on the settings page to add your current IP address to the whitelist, then click "Save Changes".

### My site is behind Cloudflare, a CDN or a proxy. Which Visitor IP Source should I use?

Leave it on "Automatic" if you use Cloudflare, or a proxy on your own server or private network. If requests reach your site through another CDN, firewall or load balancer that adds an X-Forwarded-For header, choose "Reverse proxy or load balancer". The settings page shows which IP the plugin sees for your own request, so you can check that it matches your real IP.

### Can a bot get past the blocker by pretending to be Googlebot?

No. With "Allow Known Crawlers" enabled, Googlebot, Bingbot, Yahoo Slurp, Baiduspider and YandexBot are confirmed with a reverse DNS lookup. A visitor who only fakes the User-Agent is checked like everyone else. Patterns you add under "Additional Crawler Patterns" are not verified, so only add crawlers you need.

## Changelog

### 1.5.1

- Fix: The settings page no longer warns that proxy headers are ignored when your server already passes the real visitor IP to WordPress (for example behind Cloudflare). The warning now only appears when the headers point to a different IP than the one the plugin uses.

### 1.5.0

- Refreshed the settings page with responsive sections, saved configuration status, a masked API key, inline whitelist feedback, and API test loading/error states. Assets are local and load only on the plugin settings page.
- Added clear IPHub data-sharing guidance beside setup. Existing option names and visitor blocking behavior are preserved.

### 1.4

- Security: The visitor IP is now read from the direct connection. Proxy headers (X-Forwarded-For, CF-Connecting-IP) are only trusted when the request comes from Cloudflare or a private network address, or when you choose them in the new "Visitor IP Source" setting. Previously any visitor could fake their IP to skip the check or use up your API quota.
- Security: "Allow Known Crawlers" now confirms crawlers with a reverse DNS lookup, so a faked crawler User-Agent no longer skips the check. DuckDuckBot was removed from the built-in list because it can't be verified this way; add it under "Additional Crawler Patterns" if you need it.
- Security: IPHub API requests now use HTTPS, so your API key and visitor IPs are no longer sent in plain text.
- Fix: The first save of the settings could reset them to Safe Mode, turn on "Allow Known Crawlers" and show a "Please select only one blocking mode" error.
- Fix: "Allow Known Crawlers" never matched on hosts that run PHP as CGI/FastCGI.
- Fix: WP-Cron, WP-CLI and the login page are no longer checked, so scheduled tasks keep running and admins on a VPN can still sign in.
- Fix: When the IPHub API fails or rate-limits requests, lookups pause for 5 minutes instead of slowing down every page view.
- Change: "Additional Crawler Patterns" are now plain-text, case-insensitive matches instead of regular expressions. Patterns containing "/" used to cause PHP warnings on every page. Remove regex symbols such as ^ or $ from existing entries.
- Change: The blocking mode is now a single choice with Safe Mode as the default. Sites that had the plugin enabled with no mode ticked now block in Safe Mode. Custom Mode with no block types ticked no longer makes API calls.
- The settings page shows which IP the plugin sees for your request, and warns when proxy headers are being ignored.
- Added a link to IVTB, the enterprise version for non-WordPress sites, on the settings page and the Plugins screen.
- Tested up to WordPress 7.1.

### 1.3
- Added “Allow Known Crawlers” setting to automatically bypass IP checks for common search engine bots (Googlebot, Bingbot, Slurp, DuckDuckBot, Baiduspider, YandexBot).
- Introduced “Additional Crawler Patterns” textarea so admins can specify extra User-Agent regexes to whitelist.
- Updated `invatrbl_check_visitor_ip()` to use `filter_input()` and `sanitize_text_field()` when reading `$_SERVER['HTTP_USER_AGENT']` to comply with WP security standards.
- Ensured User-Agent checks are fully sanitized to eliminate any `InputNotSanitized` warnings during plugin review.
- Streamlined front-end blocking logic so known crawlers (built-in or custom) are skipped before performing IPHub API lookups.
- Minor code refactoring and cleanup to align with WordPress Plugin Coding Standards.

### 1.2

- Use admin's current IP for API testing instead of a default.
- Fancy info boxes for API test responses (green for success, red for errors).
- "Whitelist My IP" button to add the admin's current IP to the whitelist automatically.

### 1.1

- Added whitelist functionality and basic API connectivity testing.
- Introduced multiple blocking modes.

### 1.0

- Initial release with core functionality:
  - Integration with IPHub.info API.
  - Multiple blocking modes: Safe, Strict, and Custom.
  - Persistent warning message for blocked users.
  - Basic security measures and caching implementation.

## Upgrade Notice

### 1.5.1

Fixes a false "proxy headers ignored" warning on the settings page. Updating from 1.3 or earlier? This release also stops visitors faking their IP to get past the blocker; if your site is behind a proxy or CDN other than Cloudflare, check the Visitor IP Source setting.

### 1.5.0

Security update and redesigned settings page. Stops visitors faking their IP or a crawler User-Agent to get past the blocker, and switches API calls to HTTPS. If your site is behind a proxy or CDN other than Cloudflare, check the Visitor IP Source setting after updating.

### 1.4

Security update: stops visitors faking their IP or a crawler User-Agent to get past the blocker, and switches API calls to HTTPS. If your site is behind a proxy or CDN other than Cloudflare, check the new Visitor IP Source setting after updating.

### 1.3

This update adds an option to allow known search engine crawlers and custom User-Agent patterns to bypass the IP check, and ensures full sanitization of the User-Agent header to meet WordPress security requirements.

### 1.2

This update includes improved API testing with admin IP detection, styled response messages, and an automatic whitelist option.

## Screenshots

1. Admin settings page with API key, blocking mode options, and whitelist functionality.
2. API connectivity test output showing a green info box on success.
3. Warning message for blocked users.

## External Services ##
This plugin connects to the IPHub.info API (`https://v2.api.iphub.info`) to validate IP addresses and determine whether they are suspicious, likely belonging to bots, VPNs, or invalid traffic. All requests use HTTPS.
Data transmitted:
  • The visitor's IP address, each time a visitor is checked and no cached result exists.
  • The admin's own IP address, when the "Test API Connectivity" button is clicked on the settings page.
  • Your IPHub API key, in a request header with each lookup.
Purpose:
  • To determine the nature of the IP (e.g., non‑residential, residential suspicious) and decide whether to block access.
Terms and Privacy:
  • IPHub.info Terms of Service: [https://iphub.info/legal/tos](https://iphub.info/legal/tos)
  • IPHub.info Privacy Policy: [https://iphub.info/legal/privacy](https://iphub.info/legal/privacy)

When "Allow Known Crawlers" is enabled, a visitor whose User-Agent claims to be a known search engine crawler is verified with a reverse and forward DNS lookup through your server's own DNS resolver. Nothing is sent to IPHub.info or any other service for this check.
