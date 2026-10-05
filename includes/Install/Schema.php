<?php
namespace MultisiteRadar\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Tables réseau du plugin (créées et mises à jour par dbDelta).
 */
final class Schema {

	/**
	 * 1 : tables de M1 ; 2 : colonne siteurl (M2) ; 3 : mesures de M4, remplies par une analyse complète ;
	 * 4 : tables msradar_events et msradar_snapshots (M6), sans nouvelle analyse (écart E2 du plan M6).
	 */
	public const VERSION = 4;
	public const OPTION  = 'msradar_db_version';

	public static function sites_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'msradar_sites';
	}

	public static function extensions_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'msradar_site_extensions';
	}

	public static function events_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'msradar_events';
	}

	public static function snapshots_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'msradar_snapshots';
	}

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return [ self::sites_table(), self::extensions_table(), self::events_table(), self::snapshots_table() ];
	}

	/**
	 * @return bool False si une table n'a pas pu être créée (la version n'est alors pas enregistrée).
	 */
	public static function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sites           = self::sites_table();
		$extensions      = self::extensions_table();
		$events          = self::events_table();
		$snapshots       = self::snapshots_table();

		dbDelta(
			"CREATE TABLE {$sites} (
site_id bigint(20) unsigned NOT NULL,
network_id bigint(20) unsigned NOT NULL DEFAULT 1,
name varchar(255) NOT NULL DEFAULT '',
url varchar(255) NOT NULL DEFAULT '',
siteurl varchar(255) NOT NULL DEFAULT '',
is_public tinyint(1) NOT NULL DEFAULT 1,
is_archived tinyint(1) NOT NULL DEFAULT 0,
is_spam tinyint(1) NOT NULL DEFAULT 0,
is_deleted tinyint(1) NOT NULL DEFAULT 0,
theme_stylesheet varchar(191) NOT NULL DEFAULT '',
theme_template varchar(191) NOT NULL DEFAULT '',
users_count int(10) unsigned NOT NULL DEFAULT 0,
admins_count int(10) unsigned NOT NULL DEFAULT 0,
content_count int(10) unsigned NOT NULL DEFAULT 0,
media_count int(10) unsigned NOT NULL DEFAULT 0,
disk_bytes bigint(20) unsigned DEFAULT NULL,
disk_is_estimate tinyint(1) NOT NULL DEFAULT 0,
db_bytes bigint(20) unsigned DEFAULT NULL,
autoload_bytes bigint(20) unsigned DEFAULT NULL,
last_activity_gmt datetime DEFAULT NULL,
alert_level tinyint(3) unsigned NOT NULL DEFAULT 0,
alerts_count smallint(5) unsigned NOT NULL DEFAULT 0,
alert_rules varchar(255) NOT NULL DEFAULT '',
registry_status varchar(20) NOT NULL DEFAULT 'missing',
data longtext NULL,
dirty tinyint(1) NOT NULL DEFAULT 1,
dirty_since datetime DEFAULT NULL,
scanned_at datetime DEFAULT NULL,
PRIMARY KEY  (site_id),
KEY network_id (network_id),
KEY alert_level (alert_level),
KEY last_activity_gmt (last_activity_gmt),
KEY dirty (dirty,dirty_since),
KEY theme_stylesheet (theme_stylesheet),
KEY scanned_at (scanned_at)
) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$extensions} (
site_id bigint(20) unsigned NOT NULL,
type varchar(10) NOT NULL,
slug varchar(191) NOT NULL,
role varchar(10) NOT NULL DEFAULT '',
PRIMARY KEY  (site_id,type,slug),
KEY type_slug (type,slug)
) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
network_id bigint(20) unsigned NOT NULL DEFAULT 1,
site_id bigint(20) unsigned NOT NULL DEFAULT 0,
type varchar(40) NOT NULL,
subject varchar(191) NOT NULL DEFAULT '',
meta longtext NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY network_created (network_id,created_at),
KEY site_created (site_id,created_at),
KEY type_created (type,created_at)
) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$snapshots} (
site_id bigint(20) unsigned NOT NULL,
day date NOT NULL,
network_id bigint(20) unsigned NOT NULL DEFAULT 1,
users_count int(10) unsigned NOT NULL DEFAULT 0,
content_count int(10) unsigned NOT NULL DEFAULT 0,
media_count int(10) unsigned NOT NULL DEFAULT 0,
disk_bytes bigint(20) unsigned DEFAULT NULL,
db_bytes bigint(20) unsigned DEFAULT NULL,
alert_level tinyint(3) unsigned NOT NULL DEFAULT 0,
alerts_count smallint(5) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (site_id,day),
KEY network_day (network_id,day)
) {$charset_collate};"
		);

		foreach ( self::tables() as $table ) {
			if ( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				return false;
			}
		}

		update_site_option( self::OPTION, self::VERSION );
		return true;
	}

	public static function is_current(): bool {
		return (int) get_site_option( self::OPTION, 0 ) >= self::VERSION;
	}

	public static function drop(): void {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		delete_site_option( self::OPTION );
	}
}
