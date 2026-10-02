<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Install\Installer;
use MultisiteRadar\Install\Schema;
use MultisiteRadar\Scan\Lock;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Support\MainSite;
use MultisiteRadar\Tests\TestCase;

final class QueueTest extends TestCase {

	/**
	 * Nom littéral : uninstall.php le reprend tel quel.
	 */
	private const CURSOR = 'msradar_recompute_cursor';

	private Queue $queue;

	public function set_up(): void {
		parent::set_up();
		$this->queue = $this->plugin()->queue();
	}

	public function test_schedule_and_unschedule_recurring_events(): void {
		$this->queue->unschedule();

		$this->queue->schedule();
		$this->queue->schedule();

		$this->assertSame( 1, $this->count_cron_events( Queue::HOOK_PROCESS ) );
		$this->assertSame( Queue::SCHEDULE, wp_get_schedule( Queue::HOOK_PROCESS ) );
		$this->assertSame( 'daily', wp_get_schedule( Queue::HOOK_DAILY ) );
		$this->assertSame( 300, wp_get_schedules()[ Queue::SCHEDULE ]['interval'] );
		$this->assertIsInt( Queue::next_run() );

		$this->queue->unschedule();
		$this->assertFalse( wp_next_scheduled( Queue::HOOK_PROCESS ) );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK_DAILY ) );
		$this->assertNull( Queue::next_run() );
	}

	public function test_activation_and_deactivation_drive_the_schedule(): void {
		$this->queue->unschedule();

		do_action( 'msradar_activated', true );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_PROCESS ) );

		do_action( 'msradar_deactivated' );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK_PROCESS ) );
	}

	public function test_schedule_from_a_sub_site_targets_the_main_site(): void {
		$this->queue->unschedule();
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		$this->queue->schedule();
		$on_sub_site = wp_next_scheduled( Queue::HOOK_PROCESS );
		restore_current_blog();

		$this->assertFalse( $on_sub_site );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_PROCESS ) );
	}

	public function test_process_scans_dirty_sites_and_stops_when_done(): void {
		self::factory()->blog->create_many( 2 );
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );

		$this->queue->process();

		$this->assertSame( 0, $this->plugin()->sites()->count_dirty() );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );
	}

	public function test_continue_soon_schedules_a_single_event(): void {
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );

		$this->queue->continue_soon();
		$this->queue->continue_soon();

		$this->assertSame( 1, $this->count_cron_events( Queue::HOOK_CONTINUE ) );
	}

	public function test_schedule_once_from_a_sub_site_schedules_one_event_on_the_main_site(): void {
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		MainSite::schedule_once( Queue::HOOK_CONTINUE );
		MainSite::schedule_once( Queue::HOOK_CONTINUE );
		$on_sub_site = wp_next_scheduled( Queue::HOOK_CONTINUE );
		restore_current_blog();

		$this->assertFalse( $on_sub_site );
		$this->assertSame( 1, $this->count_cron_events( Queue::HOOK_CONTINUE ) );
	}

	public function test_daily_requests_a_full_scan_when_due(): void {
		$site_id = self::factory()->blog->create();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->mark_all_clean();
		update_site_option( Queue::LAST_FULL_SCAN, time() - 8 * DAY_IN_SECONDS );

		$this->queue->daily();

		$this->assertTrue( $this->plugin()->sites()->find( $site_id )->dirty );
		$this->assertEqualsWithDelta( time(), (int) get_site_option( Queue::LAST_FULL_SCAN ), 5 );
	}

	public function test_daily_skips_the_full_scan_when_recent_and_removes_orphans(): void {
		$site_id = self::factory()->blog->create();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->plugin()->sites()->insert_pending( 424242, get_current_network_id(), 'gone.test/' );
		$this->mark_all_clean();
		update_site_option( Queue::LAST_FULL_SCAN, time() - DAY_IN_SECONDS );

		$this->queue->daily();

		$this->assertFalse( $this->plugin()->sites()->find( $site_id )->dirty );
		$this->assertNull( $this->plugin()->sites()->find( 424242 ) );
	}

	public function test_recompute_alerts_uses_stored_data_and_skips_unscanned_sites(): void {
		$this->make_record( 3001, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 3002, [ 'users_count' => 0 ] );

		$this->assertSame( 1, $this->queue->recompute_alerts() );
		$this->assertSame( 3, $this->plugin()->sites()->find( 3001 )->alert_level );
		$this->assertSame( 0, $this->plugin()->sites()->find( 3002 )->alert_level );
	}

	public function test_recompute_alerts_touches_only_the_current_network_with_its_settings(): void {
		$other  = self::factory()->network->create();
		$months = static fn ( int $months ): array => [ 'alerts' => [ 'rules' => [ 'inactive' => [ 'params' => [ 'months' => $months ] ] ] ] ];
		$props  = [
			'users_count'       => 1,
			'admins_count'      => 1,
			'scanned_at'        => '2026-09-01 00:00:00',
			'last_activity_gmt' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ),
		];
		update_site_option( Settings::OPTION, $months( 2 ) );
		update_network_option( $other, Settings::OPTION, $months( 12 ) );
		$this->make_record( 3101, $props );
		$this->make_record( 3102, array_merge( $props, [ 'network_id' => $other ] ) );

		$this->assertSame( 1, $this->queue->recompute_alerts() );
		$this->assertSame( [ 'inactive' ], $this->plugin()->sites()->find( 3101 )->alert_rule_ids() );
		$this->assertSame( [], $this->plugin()->sites()->find( 3102 )->alert_rule_ids() );

		$this->plugin()->sites()->save_alerts( $this->build_record( [ 'site_id' => 3102, 'alert_level' => 2, 'alert_rules' => ',inactive,' ] ) );
		$this->assertSame( 1, $this->as_network( $other, fn (): int => $this->queue->recompute_alerts() ) );
		$this->assertSame( [], $this->plugin()->sites()->find( 3102 )->alert_rule_ids(), 'Its own 12-month threshold applies.' );
		$this->assertSame( [ 'inactive' ], $this->plugin()->sites()->find( 3101 )->alert_rule_ids(), 'The first network is left alone.' );
	}

	public function test_recompute_stops_when_its_budget_is_spent_and_resumes_after_its_cursor(): void {
		$this->make_record( 3201, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 3202, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );

		$this->assertSame( 1, $this->queue->recompute_alerts( null, 0.0 ), 'At least one site per call.' );
		$this->assertSame( 3201, get_site_option( self::CURSOR )['after'] );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_RECOMPUTE ) );
		$this->assertSame( 3, $this->plugin()->sites()->find( 3201 )->alert_level );
		$this->assertSame( 0, $this->plugin()->sites()->find( 3202 )->alert_level );

		$this->assertSame( 1, $this->queue->recompute_alerts( null, 0.0 ) );
		$this->assertSame( 3, $this->plugin()->sites()->find( 3202 )->alert_level );
		$this->assertFalse( get_site_option( self::CURSOR ), 'Finished: the cursor is removed.' );
	}

	public function test_recompute_rewrites_only_the_sites_whose_alerts_changed(): void {
		global $wpdb;
		$this->make_record( 3301, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 3302, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->queue->recompute_alerts();
		$table = Schema::sites_table();
		$wpdb->update( $table, [ 'users_count' => 4, 'admins_count' => 1 ], [ 'site_id' => 3302 ] );
		$writes = [];
		$spy    = static function ( string $query ) use ( &$writes, $table ): string {
			if ( 0 === strpos( $query, 'UPDATE `' . $table . '`' ) && 1 === preg_match( '/`site_id` = (\d+)/', $query, $match ) ) {
				$writes[] = (int) $match[1];
			}
			return $query;
		};
		add_filter( 'query', $spy );

		$evaluated = $this->queue->recompute_alerts();
		remove_filter( 'query', $spy );

		$this->assertSame( 2, $evaluated );
		$this->assertSame( [ 3302 ], $writes );
		$this->assertSame( 0, $this->plugin()->sites()->find( 3302 )->alert_level );
		$this->assertSame( 3, $this->plugin()->sites()->find( 3301 )->alert_level );
	}

	public function test_a_settings_change_restarts_the_recompute_from_the_first_site(): void {
		update_site_option( self::CURSOR, 3201 );
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );

		$this->plugin()->settings()->update( [ 'scan' => [ 'full_rescan_days' => 14 ] ] );

		$this->assertFalse( get_site_option( self::CURSOR ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_RECOMPUTE ) );
	}

	public function test_daily_schedules_the_recompute_instead_of_running_it(): void {
		$site_id = self::factory()->blog->create();
		$this->make_record( $site_id, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		update_site_option( Queue::LAST_FULL_SCAN, time() );
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );

		$this->queue->daily();

		$this->assertSame( 0, $this->plugin()->sites()->find( $site_id )->alert_level, 'daily() only schedules the recompute; it never runs it.' );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_RECOMPUTE ) );
	}

	public function test_recompute_is_deferred_while_another_process_holds_the_lock(): void {
		$this->make_record( 3001, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );
		$other = new Lock();
		$this->assertTrue( $other->acquire() );

		$this->queue->run_recompute();
		$other->release();

		$this->assertSame( 0, $this->plugin()->sites()->find( 3001 )->alert_level );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_RECOMPUTE ) );
	}

	public function test_recompute_hook_runs_under_the_lock_and_releases_it(): void {
		$this->make_record( 3001, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );

		do_action( Queue::HOOK_RECOMPUTE );

		$this->assertSame( 3, $this->plugin()->sites()->find( 3001 )->alert_level );
		$this->assertFalse( $this->plugin()->lock()->is_locked() );
	}

	public function test_only_one_process_pass_runs_per_request(): void {
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );
		$this->queue->process();
		$this->assertSame( 0, $this->plugin()->sites()->count_dirty() );

		$this->plugin()->sites()->mark_all_dirty( get_current_network_id() );
		$dirty = $this->plugin()->sites()->count_dirty();
		$this->queue->process();

		$this->assertSame( $dirty, $this->plugin()->sites()->count_dirty() );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );
	}

	public function test_settings_update_schedules_a_recompute(): void {
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );

		$this->plugin()->settings()->update( [ 'scan' => [ 'full_rescan_days' => 14 ] ] );

		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_RECOMPUTE ) );
	}

	public function test_recompute_waits_when_a_queue_pass_already_ran_in_this_request(): void {
		$this->make_record( 3501, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->mark_all_clean();
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );
		$this->queue->process();

		$before = time();
		$this->queue->run_recompute();

		$this->assertSame( 0, $this->plugin()->sites()->find( 3501 )->alert_level, 'A single time budget per cron request.' );
		$next = wp_next_scheduled( Queue::HOOK_RECOMPUTE );
		$this->assertNotFalse( $next );
		$this->assertGreaterThanOrEqual( $before + MINUTE_IN_SECONDS, $next );
	}

	public function test_a_queue_pass_waits_when_the_recompute_already_ran_in_this_request(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->plugin()->sites()->mark_all_dirty( $network );
		$dirty = $this->plugin()->sites()->count_dirty();
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );

		$this->queue->run_recompute();
		$this->queue->process();

		$this->assertSame( $dirty, $this->plugin()->sites()->count_dirty() );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );
	}

	public function test_the_cursor_records_the_settings_it_was_computed_with(): void {
		$this->make_record( 3601, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 3602, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );

		$this->queue->recompute_alerts( null, 0.0 );

		$this->assertSame(
			[
				'after'  => 3601,
				'config' => md5( (string) wp_json_encode( $this->plugin()->settings()->get( 'alerts' ) ) ),
			],
			get_site_option( self::CURSOR )
		);
	}

	public function test_a_cursor_written_with_other_settings_restarts_from_the_first_site(): void {
		$this->make_record( 3601, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 3602, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		update_site_option(
			self::CURSOR,
			[
				'after'  => 3601,
				'config' => 'settings-of-an-older-run',
			]
		);

		$this->assertSame( 2, $this->queue->recompute_alerts() );
		$this->assertSame( 3, $this->plugin()->sites()->find( 3601 )->alert_level, 'Sites before the old cursor are evaluated again.' );
	}

	public function test_an_integer_cursor_from_2_0_0_alpha_1_restarts_from_the_first_site(): void {
		$this->make_record( 3601, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 3602, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		update_site_option( self::CURSOR, 3601 );

		$this->assertSame( 2, $this->queue->recompute_alerts() );
	}

	public function test_changing_the_activity_types_marks_every_site_for_analysis(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->mark_all_clean();
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );

		$this->plugin()->settings()->update( [ 'scan' => [ 'activity_post_types' => [ 'post' ] ] ] );

		$this->assertSame( $this->plugin()->sites()->count_all( $network ), $this->plugin()->sites()->count_dirty( $network ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );
	}

	public function test_other_setting_changes_do_not_mark_sites(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->mark_all_clean();

		$this->plugin()->settings()->update( [ 'scan' => [ 'activity_post_types' => [ 'page', 'post' ] ] ] );
		$this->plugin()->settings()->update( [ 'scan' => [ 'full_rescan_days' => 30 ] ] );

		$this->assertSame( 0, $this->plugin()->sites()->count_dirty( $network ), 'The same types in another order are not a change.' );
	}

	public function test_a_schema_upgrade_requests_a_full_analysis(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->mark_all_clean();
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );

		do_action( 'msradar_upgraded', 2 );

		$this->assertSame( $this->plugin()->sites()->count_all( $network ), $this->plugin()->sites()->count_dirty( $network ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );
	}

	public function test_a_cron_pass_upgrades_an_outdated_schema_before_scanning(): void {
		self::factory()->blog->create();
		update_site_option( Schema::OPTION, 1 );
		$upgrades = did_action( 'msradar_upgraded' );

		do_action( Queue::HOOK_PROCESS );

		$this->assertTrue( Schema::is_current() );
		$this->assertSame( $upgrades + 1, did_action( 'msradar_upgraded' ) );
		$this->assertSame( 0, $this->plugin()->sites()->count_dirty(), 'The pass then scans with the current schema.' );
	}

	public function test_the_recompute_upgrades_an_outdated_schema_first(): void {
		update_site_option( Schema::OPTION, 1 );
		$upgrades = did_action( 'msradar_upgraded' );

		do_action( Queue::HOOK_RECOMPUTE );

		$this->assertTrue( Schema::is_current() );
		$this->assertSame( $upgrades + 1, did_action( 'msradar_upgraded' ) );
	}

	public function test_cron_passes_wait_while_the_schema_cannot_be_upgraded(): void {
		self::factory()->blog->create();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->make_record( 3001, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		$dirty = $this->plugin()->sites()->count_dirty();
		update_site_option( Schema::OPTION, 1 );
		// Les tables semblent absentes après dbDelta : Schema::install() échoue et la version reste 1.
		$hide = static fn ( string $query ): string => 0 === strpos( ltrim( $query ), 'SHOW TABLES LIKE' ) ? 'SELECT 1 FROM DUAL WHERE 1 = 0' : $query;
		add_filter( 'query', $hide );
		try {
			$this->queue->process();
			$this->queue->run_recompute();
		} finally {
			remove_filter( 'query', $hide );
		}

		$this->assertFalse( Schema::is_current() );
		$this->assertSame( $dirty, $this->plugin()->sites()->count_dirty(), 'No site is scanned against an outdated schema.' );
		$this->assertSame( 0, $this->plugin()->sites()->find( 3001 )->alert_level, 'No alert is recomputed against an outdated schema.' );
	}

	public function test_a_failed_read_during_the_recompute_is_reported_and_retried(): void {
		global $wpdb;
		$this->make_record( 3001, [ 'users_count' => 0, 'scanned_at' => '2026-09-01 00:00:00' ] );
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );
		$errors   = did_action( 'msradar_error' );
		$break    = static function ( string $query ): string {
			return 0 === strpos( ltrim( $query ), 'SELECT * FROM' ) && false !== strpos( $query, Schema::sites_table() ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$this->queue->run_recompute();
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( $errors + 1, did_action( 'msradar_error' ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_RECOMPUTE ), 'Retried later rather than reported as done.' );
		$this->assertFalse( $this->plugin()->lock()->is_locked() );
	}

	public function test_an_install_from_beta_3_is_analysed_again_to_fill_the_measures(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->mark_all_clean();
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );
		update_site_option( Schema::OPTION, 2 );

		Installer::maybe_upgrade();

		$this->assertTrue( Schema::is_current() );
		$this->assertSame( $this->plugin()->sites()->count_all( $network ), $this->plugin()->sites()->count_dirty( $network ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );
	}

	public function test_switching_the_disk_measure_marks_every_site_for_analysis(): void {
		$network = get_current_network_id();
		$this->plugin()->sites()->seed_from_blogs( $network );
		$this->mark_all_clean();
		wp_clear_scheduled_hook( Queue::HOOK_CONTINUE );

		$this->plugin()->settings()->update( [ 'scan' => [ 'measure_disk' => false ] ] );

		$this->assertSame( $this->plugin()->sites()->count_all( $network ), $this->plugin()->sites()->count_dirty( $network ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_CONTINUE ) );

		$this->mark_all_clean();
		$this->plugin()->settings()->update( [ 'scan' => [ 'measure_disk' => false ] ] );
		$this->assertSame( 0, $this->plugin()->sites()->count_dirty( $network ), 'Saving the same value again is not a change.' );
	}
}
