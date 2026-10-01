<?php
namespace MultisiteRadar\Tests\Install;

use MultisiteRadar\Install\LegacyMigration;
use MultisiteRadar\Support\MainSite;
use MultisiteRadar\Tests\TestCase;

final class LegacyMigrationTest extends TestCase {

	private LegacyMigration $migration;

	public function set_up(): void {
		parent::set_up();
		$this->migration = $this->plugin()->legacy();
		foreach ( [ LegacyMigration::DONE, LegacyMigration::CURSOR, LegacyMigration::ALIASES ] as $option ) {
			delete_site_option( $option );
		}
	}

	private function migrate(): void {
		$this->migration->start();
		$guard = 0;
		while ( false !== get_site_option( LegacyMigration::CURSOR, false ) && $guard++ < 100 ) {
			$this->migration->run_menu_batch();
		}
	}

	public function test_migrates_legacy_options_and_clears_legacy_caches(): void {
		update_site_option( 'npu_activity_post_types', [ 'post', 'event' ] );
		update_site_option( 'npu_analysis_plugins', [ 'acme' ] );
		update_site_option( 'npu_enable_network_menu', 0 );
		set_site_transient( 'npu_site_data_1', [ 'cached' ], HOUR_IN_SECONDS );
		set_site_transient( 'npu_last_cache_refresh', time(), DAY_IN_SECONDS );

		$this->migrate();
		$settings = $this->plugin()->settings();

		$this->assertSame( [ 'post', 'event' ], $settings->get( 'scan.activity_post_types' ) );
		$this->assertSame( [ 'acme' ], $settings->get( 'scan.analysis_plugins' ) );
		$this->assertFalse( $settings->get( 'sites_menu.enabled' ), 'The menu was disabled in 1.x.' );
		$this->assertFalse( get_site_option( 'npu_activity_post_types' ) );
		$this->assertFalse( get_site_option( 'npu_enable_network_menu' ) );
		$this->assertFalse( get_site_transient( 'npu_site_data_1' ) );
		$this->assertFalse( get_site_transient( 'npu_last_cache_refresh' ) );
		$this->assertSame( 1, (int) get_site_option( LegacyMigration::ALIASES ) );
		$this->assertNotFalse( get_site_option( LegacyMigration::DONE ) );
		$this->assertFalse( get_site_option( LegacyMigration::CURSOR ) );
	}

	public function test_converts_legacy_menu_items_and_enables_the_menu_module(): void {
		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		$item_id = self::factory()->post->create( [ 'post_type' => 'nav_menu_item', 'post_status' => 'publish' ] );
		update_post_meta( $item_id, '_menu_item_type', 'network_site' );
		update_post_meta( $item_id, '_menu_item_object', 'network_site' );
		get_post_meta( $item_id );
		restore_current_blog();

		$this->migrate();

		switch_to_blog( $site_id );
		$type   = get_post_meta( $item_id, '_menu_item_type', true );
		$object = get_post_meta( $item_id, '_menu_item_object', true );
		restore_current_blog();

		$this->assertSame( LegacyMigration::MENU_TYPE, $type, 'The cached meta was cleared.' );
		$this->assertSame( LegacyMigration::MENU_TYPE, $object );
		$this->assertTrue( $this->plugin()->settings()->get( 'sites_menu.enabled' ), 'Menu items imply the menu was in use.' );
		$this->assertSame( 1, (int) get_site_option( LegacyMigration::ALIASES ) );
	}

	public function test_a_fresh_install_has_nothing_to_migrate(): void {
		add_filter( 'msradar_legacy_core_loaded', '__return_false' );
		$this->migrate();

		$this->assertNotFalse( get_site_option( LegacyMigration::DONE ) );
		$this->assertFalse( get_site_option( LegacyMigration::ALIASES ) );
		$this->assertFalse( $this->plugin()->settings()->get( 'sites_menu.enabled' ) );
	}

	public function test_runs_only_once(): void {
		update_site_option( LegacyMigration::DONE, time() );
		update_site_option( 'npu_analysis_plugins', [ 'acme' ] );

		$this->migration->start();

		$this->assertSame( [ 'acme' ], get_site_option( 'npu_analysis_plugins' ), 'Nothing is touched after the first run.' );
		$this->assertFalse( get_site_option( LegacyMigration::CURSOR ) );
	}

	public function test_menu_batches_are_chained_through_cron(): void {
		wp_clear_scheduled_hook( LegacyMigration::HOOK );

		$this->migration->start();

		$this->assertNotFalse( wp_next_scheduled( LegacyMigration::HOOK ) );
	}

	public function test_activation_starts_the_migration(): void {
		do_action( 'msradar_activated', true );

		$this->assertNotFalse( get_site_option( LegacyMigration::CURSOR ) );
	}

	public function test_coexistence_notice_appears_only_when_1x_is_loaded(): void {
		$user_id = self::factory()->user->create();
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );

		add_filter( 'msradar_legacy_core_loaded', '__return_false' );
		ob_start();
		$this->migration->render_coexistence_notice();
		$this->assertSame( '', ob_get_clean() );

		remove_filter( 'msradar_legacy_core_loaded', '__return_false' );
		add_filter( 'msradar_legacy_core_loaded', '__return_true' );
		ob_start();
		$this->migration->render_coexistence_notice();
		$this->assertStringContainsString( 'Network Plugin Utilities 1.x is still loaded', (string) ob_get_clean() );
	}

	public function test_default_detection_uses_the_loaded_1x_class(): void {
		$user_id = self::factory()->user->create();
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );
		require_once dirname( __DIR__ ) . '/fixtures/legacy/npu-core-stub.php';

		ob_start();
		$this->migration->render_coexistence_notice();

		$this->assertStringContainsString( 'still loaded', (string) ob_get_clean() );
	}

	public function test_an_invalid_legacy_value_does_not_drop_the_valid_ones(): void {
		update_site_option( 'npu_activity_post_types', [ str_repeat( 'a', 25 ) ] );
		update_site_option( 'npu_analysis_plugins', [ 'acme' ] );
		$default = $this->plugin()->settings()->get( 'scan.activity_post_types' );

		$this->migrate();
		$settings = $this->plugin()->settings();

		$this->assertSame( [ 'acme' ], $settings->get( 'scan.analysis_plugins' ) );
		$this->assertSame( $default, $settings->get( 'scan.activity_post_types' ) );
		$this->assertFalse( get_site_option( 'npu_analysis_plugins' ) );
	}

	private function create_menu_item( int $site_id ): int {
		switch_to_blog( $site_id );
		$item_id = self::factory()->post->create( [ 'post_type' => 'nav_menu_item', 'post_status' => 'publish' ] );
		update_post_meta( $item_id, '_menu_item_type', 'network_site' );
		update_post_meta( $item_id, '_menu_item_object', 'network_site' );
		restore_current_blog();
		return $item_id;
	}

	private function item_type( int $site_id, int $item_id ): string {
		switch_to_blog( $site_id );
		$type = (string) get_post_meta( $item_id, '_menu_item_type', true );
		restore_current_blog();
		return $type;
	}

	public function test_an_interrupted_migration_is_rescheduled_by_a_network_admin(): void {
		update_site_option( LegacyMigration::CURSOR, [ 'after' => 0, 'menu_items' => 0, 'attempts' => 0 ] );
		wp_clear_scheduled_hook( LegacyMigration::HOOK );
		$user_id = self::factory()->user->create();
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );

		$this->migration->maybe_start();

		$this->assertNotFalse( wp_next_scheduled( LegacyMigration::HOOK ) );
	}

	public function test_evidence_is_persisted_before_any_batch_runs(): void {
		update_site_option( 'npu_analysis_plugins', [ 'acme' ] );

		$this->migration->start();

		$this->assertSame( 1, (int) get_site_option( LegacyMigration::ALIASES ) );
		$this->assertTrue( $this->plugin()->settings()->get( 'sites_menu.enabled' ) );
	}

	public function test_another_networks_menu_items_are_left_untouched(): void {
		$network_id = self::factory()->network->create();
		$other_site = self::factory()->blog->create( [ 'site_id' => $network_id ] );
		$item_id    = $this->create_menu_item( $other_site );

		$this->migrate();

		$this->assertSame( 'network_site', $this->item_type( $other_site, $item_id ) );
	}

	public function test_a_failed_update_is_retried_without_advancing_the_cursor(): void {
		$site_id = self::factory()->blog->create();
		$item_id = $this->create_menu_item( $site_id );
		$prefix  = $GLOBALS['wpdb']->get_blog_prefix( $site_id );
		$fail    = static function ( $query ) use ( $prefix ) {
			return ( 0 === strpos( $query, 'UPDATE' ) && false !== strpos( $query, $prefix . 'postmeta' ) ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		wp_clear_scheduled_hook( LegacyMigration::HOOK );
		add_filter( 'query', $fail );

		$this->migration->start();
		wp_clear_scheduled_hook( LegacyMigration::HOOK );
		$this->migration->run_menu_batch();
		remove_filter( 'query', $fail );

		$cursor = get_site_option( LegacyMigration::CURSOR );
		$this->assertIsArray( $cursor );
		$this->assertLessThan( $site_id, $cursor['after'] );
		$this->assertSame( 'network_site', $this->item_type( $site_id, $item_id ) );
		$this->assertNotFalse( wp_next_scheduled( LegacyMigration::HOOK ) );

		$this->migration->run_menu_batch();

		$this->assertSame( LegacyMigration::MENU_TYPE, $this->item_type( $site_id, $item_id ) );
	}

	public function test_a_1x_install_with_defaults_is_recognised_from_the_loaded_core(): void {
		add_filter( 'msradar_legacy_core_loaded', '__return_true' );

		$this->migrate();

		$this->assertSame( 1, (int) get_site_option( LegacyMigration::ALIASES ) );
		$this->assertTrue( $this->plugin()->settings()->get( 'sites_menu.enabled' ) );
	}

	private function fail_updates_for( int $site_id ): callable {
		$prefix = $GLOBALS['wpdb']->get_blog_prefix( $site_id );
		$fail   = static function ( $query ) use ( $prefix ) {
			return ( 0 === strpos( $query, 'UPDATE' ) && false !== strpos( $query, $prefix . 'postmeta' ) ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		add_filter( 'query', $fail );
		return $fail;
	}

	public function test_evidence_survives_a_later_site_failing_in_the_same_batch(): void {
		add_filter( 'msradar_legacy_core_loaded', '__return_false' );
		$site_a = self::factory()->blog->create();
		$site_b = self::factory()->blog->create();
		$this->create_menu_item( $site_a );
		$item_b = $this->create_menu_item( $site_b );
		$fail   = $this->fail_updates_for( $site_b );
		$this->migration->start();
		delete_site_option( LegacyMigration::ALIASES );
		$this->plugin()->settings()->update( [ 'sites_menu' => [ 'enabled' => false ] ] );
		wp_clear_scheduled_hook( LegacyMigration::HOOK );

		$this->migration->run_menu_batch();
		remove_filter( 'query', $fail );

		$this->assertSame( 1, (int) get_site_option( LegacyMigration::ALIASES ) );
		$this->assertTrue( $this->plugin()->settings()->get( 'sites_menu.enabled' ) );
		$this->assertSame( $site_a, get_site_option( LegacyMigration::CURSOR )['after'] );
		$this->assertSame( 'network_site', $this->item_type( $site_b, $item_b ) );
		$this->assertNotFalse( wp_next_scheduled( LegacyMigration::HOOK ) );
	}

	public function test_a_site_failing_three_times_is_skipped(): void {
		$site_id = self::factory()->blog->create();
		$item_id = $this->create_menu_item( $site_id );
		$fail    = $this->fail_updates_for( $site_id );
		$this->migration->start();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->migration->run_menu_batch();
		}
		remove_filter( 'query', $fail );

		$this->assertFalse( get_site_option( LegacyMigration::CURSOR ), 'The migration finished.' );
		$this->assertNotFalse( get_site_option( LegacyMigration::DONE ) );
		$this->assertSame( 'network_site', $this->item_type( $site_id, $item_id ) );
	}

	public function test_deactivation_unschedules_the_menu_batch_and_reactivation_resumes_it(): void {
		delete_site_option( LegacyMigration::DONE );
		update_site_option(
			LegacyMigration::CURSOR,
			[
				'after'    => 0,
				'attempts' => 0,
			]
		);
		MainSite::schedule_once( LegacyMigration::HOOK );

		do_action( 'msradar_deactivated' );
		$this->assertFalse( wp_next_scheduled( LegacyMigration::HOOK ) );

		do_action( 'msradar_activated', true );
		$this->assertNotFalse( wp_next_scheduled( LegacyMigration::HOOK ), 'The interrupted migration resumes.' );
	}
}
