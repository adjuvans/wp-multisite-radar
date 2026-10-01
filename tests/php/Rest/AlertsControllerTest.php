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
}
