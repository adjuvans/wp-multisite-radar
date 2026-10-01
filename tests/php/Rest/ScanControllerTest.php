<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class ScanControllerTest extends RestTestCase {

	public function test_scan_endpoints_require_the_manage_capability(): void {
		$this->assertSame( 401, $this->request( 'POST', '/scan/batch' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'POST', '/scan', [ 'scope' => 'all' ] )->get_status() );
	}

	public function test_marking_then_processing_a_site(): void {
		$this->login_as_super_admin();
		$site_id = self::factory()->blog->create();
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->mark_all_clean();

		$status = $this->request( 'POST', '/scan', [ 'scope' => 'ids', 'ids' => [ $site_id ] ] )->get_data();
		$this->assertSame( 1, $status['remaining'] );

		$batch = $this->request( 'POST', '/scan/batch' )->get_data();
		$this->assertSame( 1, $batch['processed'] );
		$this->assertTrue( $batch['done'] );
		$this->assertFalse( $batch['locked'] );
		$this->assertNotNull( $this->plugin()->sites()->find( $site_id )->scanned_at );
	}

	public function test_full_scan_marks_every_site(): void {
		$this->login_as_super_admin();
		self::factory()->blog->create_many( 2 );
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->mark_all_clean();

		$status = $this->request( 'POST', '/scan', [ 'scope' => 'all' ] )->get_data();

		$this->assertSame( $status['total'], $status['remaining'] );
		$this->assertNotNull( $status['last_full_scan_gmt'] );
	}

	public function test_remaining_ignores_dirty_sites_of_other_networks(): void {
		$this->login_as_super_admin();
		$other_network = self::factory()->network->create();
		$this->make_record( 9001, [ 'dirty' => true, 'network_id' => $other_network ] );

		$status = $this->request( 'GET', '/scan/status' )->get_data();

		$this->assertSame( 0, $status['remaining'] );
	}

	public function test_a_batch_processes_only_the_current_network(): void {
		$this->login_as_super_admin();
		$other = self::factory()->network->create();
		$there = self::factory()->blog->create( [ 'network_id' => $other ] );
		$this->plugin()->sites()->seed_from_blogs( get_current_network_id() );
		$this->plugin()->sites()->seed_from_blogs( $other );

		$batch = $this->request( 'POST', '/scan/batch' )->get_data();

		$this->assertTrue( $batch['done'] );
		$this->assertSame( 0, $batch['remaining'] );
		$this->assertTrue( $this->plugin()->sites()->find( $there )->dirty, 'Left to its own network.' );
	}

	public function test_status_shape(): void {
		$this->login_as_super_admin();

		$status = $this->request( 'GET', '/scan/status' )->get_data();

		$this->assertSame( [ 'total', 'remaining', 'pending', 'locked', 'last_full_scan_gmt', 'next_run_gmt' ], array_keys( $status ) );
	}

	public function test_invalid_scope_is_rejected(): void {
		$this->login_as_super_admin();

		$this->assertSame( 400, $this->request( 'POST', '/scan', [ 'scope' => 'everything' ] )->get_status() );
	}

	public function test_scope_ids_only_marks_sites_of_the_current_network(): void {
		$this->login_as_super_admin();
		$this->make_record( 701, [ 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 702, [ 'scanned_at' => '2026-09-01 00:00:00', 'network_id' => 2 ] );

		$response = $this->request( 'POST', '/scan', [ 'scope' => 'ids', 'ids' => [ 701, 702 ] ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $this->plugin()->sites()->find( 701 )->dirty );
		$this->assertFalse( $this->plugin()->sites()->find( 702 )->dirty );
	}

	public function test_scope_ids_without_any_site_of_the_network_is_rejected(): void {
		$this->login_as_super_admin();
		$this->make_record( 702, [ 'network_id' => 2 ] );

		foreach ( [ [], [ 702 ], [ 999999 ] ] as $ids ) {
			$response = $this->request( 'POST', '/scan', [ 'scope' => 'ids', 'ids' => $ids ] );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'msradar_no_sites', $response->get_data()['code'] );
		}
	}

	public function test_a_failed_site_lookup_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$this->make_record( 701 );
		$break    = static function ( string $query ): string {
			return 0 === strpos( ltrim( $query ), 'SELECT site_id FROM' ) && false !== strpos( $query, 'IN (' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'POST', '/scan', [ 'scope' => 'ids', 'ids' => [ 701 ] ] );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'msradar_storage_error', $response->get_data()['code'] );
	}
}
