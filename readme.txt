=== Multisite Radar ===
Tags: multisite, network, audit, inventory, admin
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0-beta.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Network-wide audit for WordPress Multisite: sites, content types, users, plugins, themes and health alerts.

== Description ==

Multisite Radar gives network administrators a single, always up-to-date view of every site in a WordPress Multisite network: content types and their origin, users, active plugins and theme, activity and health alerts.

Data is collected in the background with lightweight SQL queries, so the network admin screens stay fast on networks with thousands of sites. The Overview, Sites, Alerts and Settings screens open instantly, and the Sites screen exports the current view as CSV or JSON; an optional "network sites" block, shortcode and menu items replace the 1.x menu. Nothing is sent to external services.

Health alerts flag sites without users or administrators, inactive sites, large media libraries, missing themes, pending updates, http addresses on an https network, nearly full upload quotas, heavy autoloaded options, sites hidden from search engines and overdue scheduled tasks. Each rule can be switched off, given another severity and tuned in the settings.

Source code: https://github.com/adjuvans/wp-multisite-radar

== Changelog ==

= 2.0.0-beta.4 =
* Advanced health: disk, database and autoload sizes of each site, overdue scheduled tasks.
* Eight new alert rules, from missing themes to pending updates and nearly full upload quotas.
* Every alert rule can be switched off, given another severity and tuned in the settings.

= 2.0.0-beta.3 =
* New Plugins, Themes and Users pages: what each site uses, unused plugins and themes, available updates, accounts attached to no site.
* The overview shows unused plugins and themes and available updates.
* Plugins and themes can be exported as CSV or JSON.
* Faster pages: the table library is downloaded once for all pages.

= 2.0.0-beta.2 =
* The interface is available in French, including the table controls.
* Every plugin page shows the plugin version next to its title, and a footer with the author, the licence and the third-party licences.

= 2.0.0-beta.1 =
* First beta: network admin screens (Overview, Sites with a side panel, Alerts, Settings), per-user display preferences, CSV and JSON exports.
* Optional network sites menu: block, shortcode and navigation menu items, with the 1.x aliases kept for migrated sites.
* REST: site users, alert list and preferences routes; failed reads now return an error instead of an empty list.

= 2.0.0-alpha.1 =
* First alpha of the complete rewrite: background collection, alerts, REST API and WP-CLI commands. No admin interface yet.
