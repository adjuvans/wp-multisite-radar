<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Tests\TestCase;

final class TrendsQueryTest extends TestCase {

	private const NOW = 1789560000; // 2026-09-16 12:00 UTC.

	public function set_up(): void {
		parent::set_up();
		$this->make_record(
			4801,
			[
				'name'          => 'Trend site',
				'scanned_at'    => '2026-09-01 00:00:00',
				'content_count' => 7,
				'alert_level'   => 2,
				'alerts_count'  => 1,
			]
		);
		$this->make_record(
			4802,
			[
				'network_id' => 2,
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);
		$network = get_current_network_id();
		$this->plugin()->snapshots()->capture( $network, '2026-09-15' );
		$this->plugin()->snapshots()->capture( $network, '2026-09-16' );
		$this->plugin()->snapshots()->capture( $network, '2026-05-01' );
	}

	public function test_the_network_series_starts_days_before_today(): void {
		$trends = $this->plugin()->trends_query()->network( 2, self::NOW );

		$this->assertSame( 2, $trends['days'] );
		$this->assertSame( '2026-09-15', $trends['since'] );
		$this->assertNull( $trends['site'] );
		$this->assertSame( [ '2026-09-15', '2026-09-16' ], array_column( $trends['points'], 'day' ) );
		$this->assertGreaterThanOrEqual( 1, $trends['points'][1]['alerts_warning'] );
	}

	public function test_the_period_is_bounded(): void {
		$this->assertSame( 2, $this->plugin()->trends_query()->network( 1, self::NOW )['days'] );
		$this->assertSame( 3650, $this->plugin()->trends_query()->network( 99999, self::NOW )['days'] );
	}

	public function test_the_series_of_a_site_names_its_alert_level(): void {
		$trends = $this->plugin()->trends_query()->site( 4801, 30, self::NOW );

		$this->assertSame( 4801, $trends['site'] );
		$this->assertSame( [ '2026-09-15', '2026-09-16' ], array_column( $trends['points'], 'day' ) );
		$this->assertSame( 'warning', $trends['points'][0]['alert_level'] );
		$this->assertSame( 7, $trends['points'][0]['content_count'] );
	}

	public function test_a_site_outside_the_network_has_no_series(): void {
		$this->assertNull( $this->plugin()->trends_query()->site( 4802, 30, self::NOW ) );
		$this->assertNull( $this->plugin()->trends_query()->site( 999999, 30, self::NOW ) );
	}
}
