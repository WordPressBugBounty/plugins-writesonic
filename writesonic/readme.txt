=== Writesonic ===
Contributors: Writesonic
Donate link: https://writesonic.com/
Tags: writesonic, AI writing, AI copywriting, AI writer, analytics
Requires at least: 6.0
Tested up to: 7.0.3
Stable tag: 2.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Writesonic is an AI writing tool that generates high-quality articles, blog posts, landing pages, Google & Facebook ads, emails, and more in seconds.

== Description ==

[Writesonic](https://writesonic.com "Writesonic")'s AI-powered writing assistant is used by 200,000 businesses and individuals to automate their writing and save 80% of their time and energy. Writesonic can automatically create articles, blogs posts, ads, landing pages and product descriptions by just inputting absolutely minimal amounts of text.

Using the Writesonic plugin, you can publish articles generated with Writesonic directly to your WordPress website. Just install and begin publishing!

A Quick installation guide can be found here: [Writesonic](https://docs.writesonic.com/docs/wordpress-integration "Writesonic wordpress plugin installation guide")

Note: We're Writesonic, and this is our own official integration, allowing WordPress users to use our services. It is built by our company and uses our API.

Link to Writesonic's official website: https://writesonic.com
Privacy Policy: https://writesonic.com/privacy
Terms of Service: https://writesonic.com/terms

== Frequently Asked Questions ==

= Does the Writesonic WordPress plugin require payment? =

As long as you have a Writesonic account, this plugin is 100% free to use and lets you publish your generated content directly to your WordPress website.

= Do you support custom domains on wordpress.org? =

Yes, this plugin is compatible with all custom domain wordpress.org sites.

= Can I install this plugin on multiple sites? =

Yes. The plugin is licensed under GPLv2, so you can install it on as many WordPress sites as you need, including staging and production environments.

= Is this plugin actively maintained? =

Yes. Writesonic actively maintains this plugin with security patches and feature updates. If you encounter any issues, please reach out via the WordPress.org support forum.

== Screenshots ==

![Connect your website](assets/screenshot-4.png)
![Authorize on writesonic](assets/screenshot-5.png)
![Plugin is connected](assets/screenshot-6.png)

== Changelog ==

= 2.0.0 =
* The Writesonic AI Analytics plugin is now built into this plugin — one plugin, one connection.
* If the standalone Writesonic AI Analytics plugin is installed, its API key is carried over automatically and analytics pauses until you remove it, so traffic is never counted twice.
* Requires WordPress 6.0 and PHP 7.4.
* Wordpress version support upto 7.0.3

= 1.0.6 =
* Security: Fixed Cross-Site Request Forgery (CSRF) vulnerability (CVE-2025-53262, CVSS 5.4 Medium).
* Added nonce verification and capability checks to settings page form handlers.
* Added automated release pipeline with WordPress.org SVN deployment.

= 1.0.5 =
* Internal: Added automated semantic-release and SVN deployment pipeline.

= 1.0.4 =
* CORS issue fixed.

= 1.0.3 =
* Post create and update APIs improved.
* Media upload support added.

= 1.0.2 =
* Upload bug fixes.
* Wordpress version support upto 6.3.2

= 1.0.1 =
* Some bug fixes.
* Wordpress version support upto 5.9


= 1.0 =
* Publish content from Writesonic to WordPress.

== Upgrade Notice ==

= 2.0.0 =
AI Analytics is now part of this plugin. If you use the standalone Writesonic AI Analytics plugin, your API key is copied over automatically — do not deactivate that plugin before updating, as it deletes its own key on deactivation. Analytics stays off until you enable it.

= 1.0.6 =
Security update. Fixes CSRF vulnerability (CVE-2025-53262). All users should update immediately.

= 1.0 =
Writesonic WordPress plugin.
