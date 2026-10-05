<?php
namespace MultisiteRadar\Install;

use MultisiteRadar\Capabilities;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Support\MainSite;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off migration of the 1.x options and menu items, by batches.

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
	private const MAX_ATTEMPTS   = 3;
	private const LEGACY_TYPE    = 'network_site';
	private const LEGACY_OPTIONS = [ 'npu_enable_network_menu', 'npu_activity_post_types', 'npu_analysis_plugins' ];

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'msradar_activated', [ $this, 'start' ] );
		add_action( 'msradar_deactivated', [ $this, 'unschedule' ] );
		add_action( 'admin_init', [ $this, 'maybe_start' ] );
		add_action( self::HOOK, [ $this, 'run_menu_batch' ] );
		add_action( 'network_admin_notices', [ $this, 'render_coexistence_notice' ] );
	}

	public function maybe_start(): void {
		if ( is_main_site() && current_user_can( Capabilities::MANAGE ) ) {
			$this->start();
		}
	}

	public function start(): void {
		if ( false !== get_site_option( self::DONE, false ) ) {
			return;
		}
		if ( false !== get_site_option( self::CURSOR, false ) ) {
			// Migration interrupted (plugin deactivated, fatal batch): make sure an event is pending again.
			$this->schedule_next();
			return;
		}
		$this->migrate_options();
		update_site_option(
			self::CURSOR,
			[
				'after'    => 0,
				'attempts' => 0,
			]
		);
		$this->schedule_next();
	}

	/**
	 * À la désactivation : plus d'événement en attente. Le curseur reste, et start() reprend la migration à la réactivation.
	 */
	public function unschedule(): void {
		MainSite::run(
			static function (): void {
				wp_clear_scheduled_hook( self::HOOK );
			}
		);
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
		$transients = $this->delete_legacy_transients();
		$found      = [] !== $values || $transients > 0 || $this->legacy_core_loaded();
		// En 1.x, le menu était actif tant que l'option n'avait pas été enregistrée.
		$menu_enabled = ! isset( $values['npu_enable_network_menu'] ) || (bool) $values['npu_enable_network_menu'];

		if ( ! $found ) {
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

		// Evidence persisted right away, with additive writes (safe against concurrent or replayed runs).
		update_site_option( self::ALIASES, 1 );
		if ( $menu_enabled ) {
			$this->settings->update( [ 'sites_menu' => [ 'enabled' => true ] ] );
		}

		return [
			'found'        => true,
			'menu_enabled' => $menu_enabled,
		];
	}

	public function run_menu_batch(): void {
		$cursor = get_site_option( self::CURSOR, false );
		if ( ! is_array( $cursor ) ) {
			return;
		}

		global $wpdb;
		$site_ids  = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT blog_id FROM %i WHERE site_id = %d AND blog_id > %d ORDER BY blog_id ASC LIMIT %d',
					$wpdb->blogs,
					get_current_network_id(),
					(int) $cursor['after'],
					self::BATCH
				)
			)
		);
		$converted = false;
		foreach ( $site_ids as $site_id ) {
			$count = $this->convert_menu_items( $site_id );
			if ( $count < 0 ) {
				$cursor['attempts'] = (int) ( $cursor['attempts'] ?? 0 ) + 1;
				if ( $cursor['attempts'] >= self::MAX_ATTEMPTS ) {
					// Give up on this site: advance past it.
					$cursor['attempts'] = 0;
					$cursor['after']    = $site_id;
					continue;
				}
				// Keep `after` on the last fully processed site so this one is retried.
				update_site_option( self::CURSOR, $cursor );
				MainSite::schedule_once( self::HOOK, MINUTE_IN_SECONDS );
				return;
			}
			$cursor['attempts'] = 0;
			$cursor['after']    = $site_id;
			if ( $count > 0 && ! $converted ) {
				// Persist the evidence right away: a later failure or fatal must not lose it.
				$converted = true;
				update_site_option( self::ALIASES, 1 );
				$this->settings->update( [ 'sites_menu' => [ 'enabled' => true ] ] );
			}
		}

		if ( self::BATCH === count( $site_ids ) ) {
			update_site_option( self::CURSOR, $cursor );
			$this->schedule_next();
			return;
		}
		$this->finish();
	}

	/**
	 * Returns -1 (rather than throwing) when the UPDATE fails, so the caller can retry the site later.
	 *
	 * @return int Nombre d'éléments de menu convertis sur ce site, ou -1 en cas d'échec.
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
		$updated  = true;
		if ( [] !== $post_ids ) {
			$updated = false !== $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET meta_value = %s WHERE meta_key IN ('_menu_item_type', '_menu_item_object') AND meta_value = %s",
					$table,
					self::MENU_TYPE,
					self::LEGACY_TYPE
				)
			);
		}
		$wpdb->suppress_errors( $suppress );

		if ( ! $updated ) {
			return -1;
		}
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
		if ( ! $this->legacy_core_loaded() || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Network Plugin Utilities 1.x is still loaded. Remove it: Multisite Radar replaces it and has taken over its settings.', 'multisite-radar' )
		);
	}

	/**
	 * Whether the 1.x code is loaded. Filter `msradar_legacy_core_loaded` lets tests (or a host) override the
	 * default `class_exists( 'NPU_Core', false )` detection.
	 */
	private function legacy_core_loaded(): bool {
		return (bool) apply_filters( 'msradar_legacy_core_loaded', class_exists( 'NPU_Core', false ) );
	}

	private function finish(): void {
		update_site_option( self::DONE, time() );
		delete_site_option( self::CURSOR );
	}

	/**
	 * @return int Nombre de transients 1.x supprimés.
	 */
	private function delete_legacy_transients(): int {
		global $wpdb;
		$keys = $wpdb->get_col(
			$wpdb->prepare( 'SELECT meta_key FROM %i WHERE site_id = %d AND meta_key LIKE %s', $wpdb->sitemeta, get_current_network_id(), $wpdb->esc_like( '_site_transient_npu_' ) . '%' )
		);
		foreach ( $keys as $key ) {
			delete_site_transient( substr( (string) $key, strlen( '_site_transient_' ) ) );
		}
		return count( $keys );
	}

	private function schedule_next(): void {
		MainSite::schedule_once( self::HOOK );
	}
}
