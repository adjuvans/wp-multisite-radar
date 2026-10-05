<?php
namespace MultisiteRadar\Tests\Storage;

use MultisiteRadar\Storage\SnapshotsRepository;
use MultisiteRadar\Tests\TestCase;

final class SnapshotsRepositoryTest extends TestCase {

	private const NETWORK = 77;

	private function repository(): SnapshotsRepository {
		return $this->plugin()->snapshots();
	}

	public function set_up(): void {
		parent::set_up();
		$scanned = '2026-09-01 00:00:00';
		$this->make_record(
			7701,
			[
				'network_id'    => self::NETWORK,
				'scanned_at'    => $scanned,
				'users_count'   => 3,
				'content_count' => 10,
				'media_count'   => 4,
				'disk_bytes'    => 2048,
				'alert_level'   => 3,
				'alerts_count'  => 2,
			]
		);
		$this->make_record(
			7702,
			[
				'network_id'    => self::NETWORK,
				'scanned_at'    => $scanned,
				'content_count' => 5,
				'media_count'   => 1,
				'alert_level'   => 1,
				'alerts_count'  => 1,
			]
		);
		// Jamais analysé, puis un site d'un autre réseau : ni l'un ni l'autre n'est pris.
		$this->make_record( 7703, [ 'network_id' => self::NETWORK ] );
		$this->make_record(
			7801,
			[
				'network_id' => 78,
				'scanned_at' => $scanned,
			]
		);
	}

	public function test_capture_copies_the_analysed_sites_of_the_network_once_per_day(): void {
		$this->assertSame( 2, $this->repository()->capture( self::NETWORK, '2026-09-10' ) );

		$this->make_record(
			7701,
			[
				'network_id'    => self::NETWORK,
				'scanned_at'    => '2026-09-10 00:00:00',
				'content_count' => 12,
			]
		);
		$this->assertSame( 2, $this->repository()->capture( self::NETWORK, '2026-09-10' ), 'A second capture the same day replaces the first.' );

		$series = $this->repository()->site_series( 7701, '2026-09-01' );
		$this->assertCount( 1, $series );
		$this->assertSame( '2026-09-10', $series[0]['day'] );
		$this->assertSame( 12, $series[0]['content_count'] );
		$this->assertSame( [], $this->repository()->site_series( 7703, '2026-09-01' ) );
		$this->assertSame( [], $this->repository()->site_series( 7801, '2026-09-01' ) );
	}

	public function test_network_series_sums_the_sites_and_counts_them_by_severity(): void {
		$this->repository()->capture( self::NETWORK, '2026-09-09' );
		$this->repository()->capture( self::NETWORK, '2026-09-10' );

		$series = $this->repository()->network_series( self::NETWORK, '2026-09-10' );

		$this->assertSame(
			[
				[
					'day'            => '2026-09-10',
					'sites'          => 2,
					'content_count'  => 15,
					'media_count'    => 5,
					'alerts_error'   => 1,
					'alerts_warning' => 0,
					'alerts_info'    => 1,
				],
			],
			$series
		);
	}

	public function test_site_series_keeps_null_measures(): void {
		$this->repository()->capture( self::NETWORK, '2026-09-10' );

		$point = $this->repository()->site_series( 7702, '2026-09-01' )[0];

		$this->assertNull( $point['disk_bytes'] );
		$this->assertNull( $point['db_bytes'] );
		$this->assertSame( 1, $point['alert_level'] );
	}

	public function test_purge_removes_old_days_of_its_network_only(): void {
		$this->repository()->capture( self::NETWORK, '2025-01-01' );
		$this->repository()->capture( self::NETWORK, '2026-09-10' );
		$this->repository()->capture( 78, '2025-01-01' );

		$this->assertSame( 2, $this->repository()->purge( self::NETWORK, '2026-01-01' ) );

		$this->assertCount( 1, $this->repository()->site_series( 7701, '2000-01-01' ) );
		$this->assertCount( 1, $this->repository()->site_series( 7801, '2000-01-01' ) );
	}
}
