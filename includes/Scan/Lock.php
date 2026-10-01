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
	private string $token;
	private string $held = '';

	public function __construct( int $ttl = 120 ) {
		$this->ttl   = $ttl;
		$this->token = wp_generate_password( 20, false );
	}

	private function value( int $expires ): string {
		return $expires . ':' . $this->token;
	}

	public function acquire(): bool {
		global $wpdb;
		$now      = time();
		$value    = $this->value( $now + $this->ttl );
		$inserted = $wpdb->query(
			$wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::table(), self::NAME, $value )
		);
		if ( 1 !== (int) $inserted ) {
			$taken = $wpdb->query(
				$wpdb->prepare( "UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d", self::table(), $value, self::NAME, $now )
			);
			if ( 1 !== (int) $taken ) {
				return false;
			}
		}
		$this->held = $value;
		return true;
	}

	/**
	 * Prolonge le verrou s'il appartient encore à cette instance ; false s'il a été repris.
	 */
	public function refresh(): bool {
		global $wpdb;
		if ( '' === $this->held ) {
			return false;
		}
		$value = $this->value( time() + $this->ttl );
		// Le jeton est alphanumérique : aucun joker LIKE à échapper.
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value LIKE %s', self::table(), $value, self::NAME, '%:' . $this->token )
		);
		// Une valeur inchangée (même seconde) compte 0 ligne modifiée : on relit pour savoir si le verrou est toujours le nôtre.
		if ( $this->current() !== $value && ! $this->owns_current() ) {
			return false;
		}
		$this->held = $value;
		return true;
	}

	public function release(): void {
		global $wpdb;
		if ( '' === $this->held ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value LIKE %s', self::table(), self::NAME, '%:' . $this->token ) );
		$this->held = '';
	}

	private function current(): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', self::table(), self::NAME ) );
		return null === $value ? null : (string) $value;
	}

	private function owns_current(): bool {
		$value  = $this->current();
		$suffix = ':' . $this->token;
		return null !== $value && substr( $value, -strlen( $suffix ) ) === $suffix;
	}

	public function is_locked(): bool {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', self::table(), self::NAME ) );
		return null !== $value && (int) strtok( (string) $value, ':' ) >= time();
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->get_blog_prefix( get_main_site_id( get_main_network_id() ) ) . 'options';
	}
}
