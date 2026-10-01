<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Tests\TestCase;

final class AlertsQueryTest extends TestCase {

	public function test_summary_counts_sites_by_severity_and_rule(): void {
		$scanned = '2026-09-01 00:00:00';
		$this->make_record( 301, [ 'scanned_at' => $scanned, 'alert_level' => 3, 'alerts_count' => 2, 'alert_rules' => ',no_users,high_media,' ] );
		$this->make_record( 302, [ 'scanned_at' => $scanned, 'alert_level' => 2, 'alerts_count' => 1, 'alert_rules' => ',inactive,' ] );
		$this->make_record( 303, [ 'scanned_at' => $scanned ] );
		$this->make_record( 304 );

		$summary = $this->plugin()->alerts_query()->summary( get_current_network_id() );

		$this->assertSame( 4, $summary['total_sites'] );
		$this->assertSame( 3, $summary['scanned_sites'] );
		$this->assertSame( 1, $summary['pending_sites'] );
		$this->assertSame( 2, $summary['sites_with_alerts'] );
		$this->assertSame( [ 'error' => 1, 'warning' => 1, 'info' => 0 ], $summary['by_severity'] );
		$this->assertSame(
			[
				[ 'rule' => 'no_users', 'label' => 'Site without users', 'count' => 1 ],
				[ 'rule' => 'inactive', 'label' => 'Inactive site', 'count' => 1 ],
				[ 'rule' => 'high_media', 'label' => 'Many media files', 'count' => 1 ],
			],
			$summary['by_rule']
		);
	}
}
