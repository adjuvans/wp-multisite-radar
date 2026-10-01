<?php
namespace MultisiteRadar\Tests\Rest;

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
}
