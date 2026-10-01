<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Support\MainSite;
use MultisiteRadar\Tests\TestCase;

final class QueueTest extends TestCase {

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

	public function test_settings_update_schedules_a_recompute(): void {
		wp_clear_scheduled_hook( Queue::HOOK_RECOMPUTE );

		$this->plugin()->settings()->update( [ 'scan' => [ 'full_rescan_days' => 14 ] ] );

		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_RECOMPUTE ) );
	}
}
