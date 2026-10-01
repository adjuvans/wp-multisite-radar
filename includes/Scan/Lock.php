<?php
namespace MultisiteRadar\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Verrou réseau pour qu'une seule analyse tourne à la fois (cron, REST, WP-CLI).
 * Stocké dans la table options du site principal, dont l'index unique rend la prise atomique.
 */
final class Lock {

	public const NAME = 'msradar_scan_lock';

	private int $ttl;

	public function __construct( int $ttl = 120 ) {
		$this->ttl = $ttl;
	}

	public function acquire(): bool {
		global $wpdb;
		$now      = time();
		$expires  = (string) ( $now + $this->ttl );
		$inserted = $wpdb->query(
			$wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::table(), self::NAME, $expires )
		);
		if ( 1 === (int) $inserted ) {
			return true;
		}
		$taken = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d', self::table(), $expires, self::NAME, $now )
		);
		return 1 === (int) $taken;
	}

	public function refresh(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', self::table(), (string) ( time() + $this->ttl ), self::NAME )
		);
	}

	public function release(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', self::table(), self::NAME ) );
	}

	public function is_locked(): bool {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', self::table(), self::NAME ) );
		return null !== $value && (int) $value >= time();
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->get_blog_prefix( get_main_site_id() ) . 'options';
	}
}
