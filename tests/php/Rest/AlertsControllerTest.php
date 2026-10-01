<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Tests\RestTestCase;

final class AlertsControllerTest extends RestTestCase {

	public function test_summary(): void {
		$this->assertSame( 401, $this->request( 'GET', '/alerts/summary' )->get_status() );

		$this->login_as_super_admin();
		$this->make_record( 401, [ 'scanned_at' => '2026-09-01 00:00:00', 'alert_level' => 3, 'alert_rules' => ',no_users,' ] );
		$summary = $this->request( 'GET', '/alerts/summary' )->get_data();

		$this->assertSame( 1, $summary['by_severity']['error'] );
		$this->assertSame( 1, $summary['sites_with_alerts'] );
	}

	public function test_a_failed_summary_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'AS with_alerts' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/alerts/summary' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}

	public function test_lists_alerts_with_pagination_headers_and_validation(): void {
		$this->assertSame( 401, $this->request( 'GET', '/alerts' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/alerts' )->get_status() );

		$this->login_as_super_admin();
		$this->make_record(
			611,
			[
				'name'        => 'Alpha',
				'scanned_at'  => '2026-09-01 00:00:00',
				'alert_level' => 3,
				'alert_rules' => ',no_users,',
				'data'        => [ 'alerts' => [ [ 'rule' => 'no_users', 'severity' => 'error', 'args' => [] ] ] ],
			]
		);

		$response = $this->request( 'GET', '/alerts', [ 'per_page' => 1, 'severity' => 'error,warning' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '1', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( '611:no_users', $response->get_data()[0]['id'] );

		foreach ( [ [ 'severity' => [ 'fatal' ] ], [ 'rule' => [ 'Bad,Rule' ] ], [ 'orderby' => 'site_id' ], [ 'per_page' => 101 ] ] as $params ) {
			$this->assertSame( 400, $this->request( 'GET', '/alerts', $params )->get_status() );
		}
	}

	public function test_a_failed_read_of_the_alerting_sites_is_a_500_not_an_empty_page(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$this->make_record(
			612,
			[
				'scanned_at'  => '2026-09-01 00:00:00',
				'alert_level' => 3,
				'alert_rules' => ',no_users,',
			]
		);
		$break    = static function ( string $query ): string {
			return 0 === strpos( ltrim( $query ), 'SELECT * FROM' ) && false !== strpos( $query, Schema::sites_table() ) && false !== strpos( $query, 'IN (' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/alerts' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'msradar_storage_error', $response->get_data()['code'] );
	}
}
