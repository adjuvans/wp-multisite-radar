<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Scan\Lock;
use MultisiteRadar\Tests\TestCase;

final class BatchRunnerTest extends TestCase {

	private function seed(): void {
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
	}

	public function test_scans_every_dirty_site_and_stores_the_results(): void {
		$site_id = self::factory()->blog->create( [ 'title' => 'Scanned site' ] );
		update_blog_option( $site_id, 'active_plugins', [ 'acme/acme.php' ] );
		$this->seed();
		$scanned = [];
		add_action(
			'msradar_site_scanned',
			static function ( int $id ) use ( &$scanned ): void {
				$scanned[] = $id;
			}
		);

		$result = $this->plugin()->runner()->run( 60.0 );
		$record = $this->plugin()->sites()->find( $site_id );

		$this->assertFalse( $result['locked'] );
		$this->assertSame( 0, $result['remaining'] );
		$this->assertContains( $site_id, $scanned );
		$this->assertSame( 'Scanned site', $record->name );
		$this->assertNotNull( $record->scanned_at );
		$this->assertFalse( $record->dirty );
		$this->assertContains(
			[ 'type' => 'plugin', 'slug' => 'acme/acme.php', 'role' => 'local' ],
			$this->plugin()->extensions()->for_site( $site_id )
		);
		$this->assertFalse( $this->plugin()->lock()->is_locked(), 'The lock is released.' );
	}

	public function test_alerts_are_evaluated_during_the_scan(): void {
		global $wpdb;
		$site_id = self::factory()->blog->create();
		$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => $wpdb->get_blog_prefix( $site_id ) . 'capabilities' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$this->seed();

		$this->plugin()->runner()->run( 60.0 );
		$record = $this->plugin()->sites()->find( $site_id );

		$this->assertSame( 3, $record->alert_level );
		$this->assertSame( [ 'no_users' ], $record->alert_rule_ids() );
	}

	public function test_processes_at_least_one_site_even_without_budget(): void {
		self::factory()->blog->create_many( 2 );
		$this->seed();
		$total = $this->plugin()->sites()->count_dirty();

		$result = $this->plugin()->runner()->run( 0.0 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( $total - 1, $result['remaining'] );
	}

	public function test_locked_runs_the_callback_under_the_lock_and_releases_it(): void {
		$called = false;

		$result = $this->plugin()->runner()->locked(
			function () use ( &$called ): void {
				$called = true;
				$this->assertTrue( $this->plugin()->lock()->is_locked() );
			}
		);

		$this->assertTrue( $result );
		$this->assertTrue( $called );
		$this->assertFalse( $this->plugin()->lock()->is_locked() );
	}

	public function test_locked_does_nothing_when_the_lock_is_held(): void {
		$other = new Lock();
		$this->assertTrue( $other->acquire() );
		$called = false;

		$result = $this->plugin()->runner()->locked(
			static function () use ( &$called ): void {
				$called = true;
			}
		);
		$other->release();

		$this->assertFalse( $result );
		$this->assertFalse( $called );
	}

	public function test_reports_a_lock_held_by_another_process(): void {
		$this->seed();
		$this->assertTrue( $this->plugin()->lock()->acquire() );

		$result = $this->plugin()->runner()->run( 60.0 );
		$this->plugin()->lock()->release();

		$this->assertTrue( $result['locked'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertGreaterThan( 0, $result['remaining'] );
	}

	public function test_a_broken_site_is_recorded_and_does_not_block_the_queue(): void {
		global $wpdb;
		$broken  = self::factory()->blog->create();
		$healthy = self::factory()->blog->create();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $wpdb->get_blog_prefix( $broken ) . 'posts' ) );
		$this->seed();

		$result = $this->plugin()->runner()->run( 60.0 );
		$record = $this->plugin()->sites()->find( $broken );

		$this->assertSame( 0, $result['remaining'] );
		$this->assertArrayHasKey( 'scan_error', $record->data );
		$this->assertNotSame( '', $record->data['scan_error']['message'] );
		$this->assertFalse( $record->dirty );
		$this->assertNull( $record->scanned_at );
		$this->assertNotNull( $this->plugin()->sites()->find( $healthy )->scanned_at );
	}

	public function test_a_site_deleted_while_queued_loses_its_row(): void {
		$this->plugin()->sites()->insert_pending( 999999, get_current_network_id(), 'gone.test/' );

		$this->plugin()->runner()->run( 60.0 );

		$this->assertNull( $this->plugin()->sites()->find( 999999 ) );
	}

	public function test_a_mark_set_during_the_scan_survives(): void {
		$site_id = self::factory()->blog->create();
		$this->seed();
		add_filter(
			'msradar_excluded_post_types',
			function ( array $types ) use ( $site_id ): array {
				$this->plugin()->sites()->mark_dirty( [ $site_id ] );
				return $types;
			}
		);

		$this->plugin()->runner()->scan_site( $site_id );

		$this->assertTrue( $this->plugin()->sites()->find( $site_id )->dirty );
	}

	public function test_a_storage_failure_is_recorded_and_the_queue_continues(): void {
		global $wpdb;
		$failing = self::factory()->blog->create();
		$healthy = self::factory()->blog->create();
		$this->seed();
		$table = $wpdb->base_prefix . 'msradar_sites';
		$fired = 0;
		$guard = static function ( string $query ) use ( &$fired, $table, $failing ): string {
			if ( 0 === $fired && 0 === strpos( $query, 'UPDATE `' . $table . '`' ) && false !== strpos( $query, '`site_id` = ' . $failing ) ) {
				++$fired;
				return 'UPDATE msradar_no_such_table SET x = 1';
			}
			return $query;
		};
		add_filter( 'query', $guard );
		$previous = $wpdb->suppress_errors( true );

		$result = $this->plugin()->runner()->run( 60.0 );
		$wpdb->suppress_errors( $previous );
		remove_filter( 'query', $guard );
		$record = $this->plugin()->sites()->find( $failing );

		$this->assertSame( 1, $fired );
		$this->assertSame( 0, $result['remaining'] );
		$this->assertArrayHasKey( 'scan_error', $record->data );
		$this->assertNotSame( '', $record->data['scan_error']['message'] );
		$this->assertFalse( $record->dirty );
		$this->assertNotNull( $this->plugin()->sites()->find( $healthy )->scanned_at );
		$this->assertFalse( $this->plugin()->lock()->is_locked() );
	}

	public function test_an_extensions_failure_is_recorded_and_the_queue_continues(): void {
		global $wpdb;
		$failing = self::factory()->blog->create();
		$healthy = self::factory()->blog->create();
		$this->seed();
		$table = $wpdb->base_prefix . 'msradar_site_extensions';
		$fired = 0;
		$guard = static function ( string $query ) use ( &$fired, $table, $failing ): string {
			if ( 0 === $fired && 0 === strpos( $query, 'INSERT INTO `' . $table . '`' ) && false !== strpos( $query, 'VALUES (' . $failing . ',' ) ) {
				++$fired;
				return 'SELECT * FROM msradar_no_such_table';
			}
			return $query;
		};
		add_filter( 'query', $guard );
		$previous = $wpdb->suppress_errors( true );
		$results  = [];

		$result = $this->plugin()->runner()->run(
			60.0,
			static function ( int $id, bool $ok ) use ( &$results ): void {
				$results[ $id ] = $ok;
			}
		);
		$wpdb->suppress_errors( $previous );
		remove_filter( 'query', $guard );
		$record = $this->plugin()->sites()->find( $failing );

		$this->assertSame( 1, $fired );
		$this->assertFalse( $results[ $failing ] );
		$this->assertTrue( $results[ $healthy ] );
		$this->assertSame( 0, $result['remaining'] );
		$this->assertArrayHasKey( 'scan_error', $record->data );
		$this->assertNull( $record->scanned_at );
		$this->assertNotNull( $this->plugin()->sites()->find( $healthy )->scanned_at );
	}

	public function test_a_site_that_vanishes_during_a_failing_collection_is_not_recreated(): void {
		global $wpdb;
		$site_id = self::factory()->blog->create();
		$this->seed();
		$this->plugin()->extensions()->replace_for_site( $site_id, [ 'a.php' ], '', '' );
		$posts = '`' . $wpdb->get_blog_prefix( $site_id ) . 'posts`';
		$fired = false;
		$guard = static function ( string $query ) use ( &$fired, $posts, $site_id ): string {
			if ( ! $fired && false !== strpos( $query, $posts ) ) {
				$fired = true;
				global $wpdb;
				$wpdb->delete( $wpdb->blogs, [ 'blog_id' => $site_id ] );
				clean_blog_cache( $site_id );
				return 'SELECT * FROM msradar_no_such_table';
			}
			return $query;
		};
		add_filter( 'query', $guard );
		$previous = $wpdb->suppress_errors( true );

		$ok = $this->plugin()->runner()->scan_site( $site_id );
		$wpdb->suppress_errors( $previous );
		remove_filter( 'query', $guard );

		$this->assertTrue( $fired );
		$this->assertFalse( $ok );
		$this->assertNull( $this->plugin()->sites()->find( $site_id ) );
		$this->assertSame( [], $this->plugin()->extensions()->for_site( $site_id ) );
	}
}
