<?php
namespace MultisiteRadar\Install;

use MultisiteRadar\Capabilities;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Support\MainSite;

defined( 'ABSPATH' ) || exit;

/**
 * Reprise d'une installation Network Plugin Utilities 1.x : réglages, caches, éléments de menu.
 * S'exécute une seule fois. Les éléments de menu sont convertis par lots de sites, via des événements cron enchaînés.
 */
final class LegacyMigration {

	public const DONE      = 'msradar_legacy_migrated';
	public const CURSOR    = 'msradar_legacy_menu_cursor';
	public const ALIASES   = 'msradar_legacy_aliases';
	public const HOOK      = 'msradar_legacy_menu_batch';
	public const MENU_TYPE = 'msradar_site';

	private const BATCH          = 50;
	private const LEGACY_TYPE    = 'network_site';
	private const LEGACY_OPTIONS = [ 'npu_enable_network_menu', 'npu_activity_post_types', 'npu_analysis_plugins' ];

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'msradar_activated', [ $this, 'start' ] );
		add_action( 'admin_init', [ $this, 'maybe_start' ] );
		add_action( self::HOOK, [ $this, 'run_menu_batch' ] );
		add_action( 'network_admin_notices', [ $this, 'render_coexistence_notice' ] );
	}

	public function maybe_start(): void {
		if ( is_main_site() ) {
			$this->start();
		}
	}

	public function start(): void {
		if ( false !== get_site_option( self::DONE, false ) || false !== get_site_option( self::CURSOR, false ) ) {
			return;
		}
		$found = $this->migrate_options();
		update_site_option(
			self::CURSOR,
			[
				'after'         => 0,
				'menu_items'    => 0,
				'options_found' => $found['found'],
				'menu_enabled'  => $found['menu_enabled'],
			]
		);
		$this->schedule_next();
	}

	/**
	 * @return array{found: bool, menu_enabled: bool}
	 */
	public function migrate_options(): array {
		$values = [];
		foreach ( self::LEGACY_OPTIONS as $name ) {
			$value = get_site_option( $name, null );
			if ( null !== $value ) {
				$values[ $name ] = $value;
			}
		}
		$this->delete_legacy_transients();

		if ( [] === $values ) {
			return [
				'found'        => false,
				'menu_enabled' => true,
			];
		}

		$scan = [];
		if ( isset( $values['npu_activity_post_types'] ) && is_array( $values['npu_activity_post_types'] ) ) {
			$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $values['npu_activity_post_types'] ) ) ) );
			if ( [] !== $types ) {
				$scan['activity_post_types'] = $types;
			}
		}
		if ( isset( $values['npu_analysis_plugins'] ) && is_array( $values['npu_analysis_plugins'] ) ) {
			$scan['analysis_plugins'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $values['npu_analysis_plugins'] ) ) ) );
		}
		// Une valeur 1.x invalide (ex. type de plus de 20 caractères) est ignorée clé par clé : les défauts v2 s'appliquent.
		foreach ( $scan as $key => $value ) {
			$this->settings->update( [ 'scan' => [ $key => $value ] ] );
		}

		foreach ( self::LEGACY_OPTIONS as $name ) {
			delete_site_option( $name );
		}

		return [
			'found'        => true,
			// En 1.x, le menu était actif tant que l'option n'avait pas été enregistrée.
			'menu_enabled' => ! isset( $values['npu_enable_network_menu'] ) || (bool) $values['npu_enable_network_menu'],
		];
	}

	public function run_menu_batch(): void {
		$cursor = get_site_option( self::CURSOR, false );
		if ( ! is_array( $cursor ) ) {
			return;
		}

		global $wpdb;
		$site_ids = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i WHERE blog_id > %d ORDER BY blog_id ASC LIMIT %d', $wpdb->blogs, (int) $cursor['after'], self::BATCH ) )
		);
		foreach ( $site_ids as $site_id ) {
			$cursor['menu_items'] = (int) $cursor['menu_items'] + $this->convert_menu_items( $site_id );
			$cursor['after']      = $site_id;
		}

		if ( self::BATCH === count( $site_ids ) ) {
			update_site_option( self::CURSOR, $cursor );
			$this->schedule_next();
			return;
		}
		$this->finish( $cursor );
	}

	/**
	 * @return int Nombre d'éléments de menu convertis sur ce site.
	 */
	public function convert_menu_items( int $site_id ): int {
		global $wpdb;
		$table    = $wpdb->get_blog_prefix( $site_id ) . 'postmeta';
		$suppress = $wpdb->suppress_errors( true );
		$post_ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM %i WHERE meta_key IN ('_menu_item_type', '_menu_item_object') AND meta_value = %s",
					$table,
					self::LEGACY_TYPE
				)
			)
		);
		if ( [] !== $post_ids ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET meta_value = %s WHERE meta_key IN ('_menu_item_type', '_menu_item_object') AND meta_value = %s",
					$table,
					self::MENU_TYPE,
					self::LEGACY_TYPE
				)
			);
		}
		$wpdb->suppress_errors( $suppress );

		if ( [] === $post_ids ) {
			return 0;
		}
		switch_to_blog( $site_id );
		foreach ( $post_ids as $post_id ) {
			clean_post_cache( $post_id );
		}
		restore_current_blog();
		return count( $post_ids );
	}

	public function render_coexistence_notice(): void {
		if ( ! class_exists( 'NPU_Core', false ) || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Network Plugin Utilities 1.x is still loaded. Remove it: Multisite Radar replaces it and has taken over its settings.', 'multisite-radar' )
		);
	}

	private function finish( array $cursor ): void {
		$menu_items = (int) $cursor['menu_items'];
		if ( ! empty( $cursor['options_found'] ) || $menu_items > 0 ) {
			update_site_option( self::ALIASES, 1 );
			if ( $menu_items > 0 || ! empty( $cursor['menu_enabled'] ) ) {
				$this->settings->update( [ 'sites_menu' => [ 'enabled' => true ] ] );
			}
		}
		update_site_option( self::DONE, time() );
		delete_site_option( self::CURSOR );
	}

	private function delete_legacy_transients(): void {
		global $wpdb;
		$keys = $wpdb->get_col(
			$wpdb->prepare( 'SELECT meta_key FROM %i WHERE meta_key LIKE %s', $wpdb->sitemeta, $wpdb->esc_like( '_site_transient_npu_' ) . '%' )
		);
		foreach ( $keys as $key ) {
			delete_site_transient( substr( (string) $key, strlen( '_site_transient_' ) ) );
		}
	}

	private function schedule_next(): void {
		MainSite::schedule_once( self::HOOK );
	}
}
