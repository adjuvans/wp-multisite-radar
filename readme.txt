=== Multisite Radar ===
Contributors: adjuvans, cyrilledegourcy
Tags: multisite, network, audit, inventory, admin
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0-rc.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Network-wide audit for WordPress Multisite: sites, content types, users, plugins, themes and health alerts.

== Description ==

Multisite Radar gives network administrators a single, always up-to-date view of every site of a WordPress Multisite network: content types and the plugin or theme that registers them, users, plugins, themes, activity and health alerts.

It is a read-only audit tool: it never changes a site. Data is collected in the background with lightweight SQL queries, so the network admin screens open instantly, even on networks with thousands of sites. Nothing is sent to external services.

= Sites =

* One table for every site of the network: theme, users, published content, media, last activity, disk and database size, alerts. Search, filters, sorting, and CSV or JSON export of the current view.
* A side panel for each site: content types with their origin, users by role, plugins and theme, alerts and history.
* Content types and taxonomies are read in the context of each site: a post type registered by a plugin that is active on one site only appears on that site, with its label and its plugin.

= Inventory =

* Plugins and themes: which sites use each one, network-activated plugins, unused plugins and themes, available updates.
* Users: accounts and their roles on each site, super admins, accounts attached to no site.

= Health alerts =

Eleven rules flag sites without users or administrators, inactive sites, large media libraries, missing themes, pending updates, http addresses on an https network, nearly full upload quotas, heavy autoloaded options, sites hidden from search engines and overdue scheduled tasks. Each rule can be switched off, given another severity and tuned in the settings. Other plugins can add their own rules.

= History and reports =

* A journal of changes: sites created or deleted, plugins activated or deactivated, themes switched, alerts raised or resolved.
* Daily figures of each site, with trend charts for the network and for each site.
* An optional weekly e-mail summary, and a widget on the network dashboard.

= Command line and AI assistants =

* `wp multisite-radar` scans the network, lists sites, alerts, plugins and themes, exports them as CSV or JSON, and reads or changes the settings.
* Six read-only abilities of the WordPress Abilities API describe the network, its sites, the use of each plugin and theme, its alerts and its recent changes. A setting, off by default, offers them to AI assistants through the MCP Adapter plugin.

= Network sites menu =

An optional "Network sites" block, shortcode and navigation menu items list the public sites of the network. They replace the menu of Network Plugin Utilities 1.x.

= Privacy =

Multisite Radar makes no external request and collects nothing from visitors. To build its inventory, it keeps in the network database a copy of data that WordPress already holds, such as the user IDs and logins of administrators and editors, and a history of the changes of the network for the retention period set in its settings. A text for your privacy policy is suggested in the privacy policy guide. Deleting the plugin deletes all its data.

= Source code =

Multisite Radar is developed on GitHub, with its uncompiled JavaScript and its build tools: https://github.com/adjuvans/wp-multisite-radar

== Installation ==

1. In the network admin, go to Plugins > Add Plugin, search for "Multisite Radar" and install it. You can also upload the `multisite-radar` folder to `/wp-content/plugins/`.
2. Network-activate it in Network Admin > Plugins. Multisite Radar requires a multisite network.
3. Open Network Admin > Multisite Radar. The first analysis of the network starts from the Overview screen. On a large network, it continues in the background with WP-Cron; `wp multisite-radar scan --all` runs it in one go.

= Coming from Network Plugin Utilities 1.x =

Remove the Network Plugin Utilities must-use plugin (its folder and its loader file in `wp-content/mu-plugins`), then network-activate Multisite Radar. The 1.x settings and the "network site" menu items are converted, and the `[network_sites_menu]` shortcode and the `rdc_network_sites_menu()` function keep working. While the must-use plugin is still loaded, a notice asks you to remove it.

== Frequently Asked Questions ==

= Does it work on a single site? =

No. Multisite Radar audits multisite networks; on a single site, it only shows a notice.

= Who can see the Multisite Radar screens? =

Super admins. Viewing requires the `msradar_view` capability (`manage_network` by default); changing the settings or starting an analysis requires `msradar_manage` (`manage_network_options` by default). The `msradar_capability_map` filter maps them to other capabilities.

= Will it slow down my network? =

No. The screens read figures stored in the plugin's own tables. The analysis runs in the background, in small batches with a time limit, and only for the sites that changed.

= Why is a site marked "Not verified"? =

Content types and taxonomies are read in each site's own context, with its plugins and theme loaded: a scheduled task of the site does it after its next visit. A site that nobody visits stays "Not verified" until then; `wp multisite-radar scan --all --probe` reads every site at once.

= Does it send data anywhere? =

No. Multisite Radar makes no external request. If you enable the weekly summary, it is sent with `wp_mail()` to the addresses you choose.

= Can it change my sites? =

No. Multisite Radar only reads.

= How do I add my own alert rule? =

Use the `msradar_alert_rules` filter to add an instance of a class that implements `MultisiteRadar\Alerts\RuleInterface`. Your rule then appears in the settings, where it can be switched off or given another severity, like the built-in rules.

= What does uninstalling remove? =

Deleting the plugin in Network Admin > Plugins removes its tables, its network options, the display preferences of each user, the data it stored on each site and its scheduled tasks.

== Screenshots ==

1. Overview: network figures, sites to review, recent changes and alerts of the last 30 days.
2. Sites: every site of the network with its theme, users, content, activity and alerts, with filters and CSV or JSON export.
3. Side panel of a site: content types with the plugin or theme that registers them.
4. Alerts: one line per site and rule, by severity.
5. Plugins: the sites that use each plugin, network-activated plugins and unused plugins.
6. Reports: trends of the network and journal of changes.
7. Settings: switch an alert rule off, change its severity or its threshold.

== Changelog ==

= 2.0.0-rc.1 =
* Release candidate of 2.0.0, ready for WordPress.org.
* The "Network sites" block loads the sites of the network on demand instead of the whole list.
* Analysing the selected sites again during an analysis now says so, and the Sites table fits a 1440 px screen.
* Charts: higher contrast, a stroke pattern for each series, round isolated points and a message when no figures exist.
* Weekly summary: a summary missed by a late WP-Cron goes out on the next days, once, and its week of changes is always kept.

= 2.0.0-beta.6 =
* History: journal of changes, daily figures and trend charts for the network and for each site.
* New Reports page, weekly e-mail summary and network dashboard widget.
* A sixth read-only ability lists the latest changes of the network: sites created or deleted, plugins activated or deactivated, themes switched, alerts raised or resolved, filterable by date, kind and site.

= 2.0.0-beta.5 =
* WP-CLI: alerts, plugins and themes lists, exports and settings from the command line.
* Five read-only abilities for the WordPress Abilities API, optionally offered to AI assistants through MCP.

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

== Upgrade Notice ==

= 2.0.0 =
Complete rewrite of Network Plugin Utilities 1.x. Remove the 1.x must-use plugin, then network-activate Multisite Radar: its settings and menu items are converted.
