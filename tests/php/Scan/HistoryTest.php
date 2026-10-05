<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Scan\History;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Tests\TestCase;

final class HistoryTest extends TestCase {

	private const NOW = 1789560000; // 2026-09-16 (UTC).

	public function test_the_daily_task_takes_the_snapshot_of_the_day(): void {
		$this->make_record(
			4501,
			[
				'name'          => 'History site',
				'scanned_at'    => '2026-09-01 00:00:00',
				'content_count' => 8,
			]
		);

		$this->plugin()->history()->daily( self::NOW );

		$series = $this->plugin()->snapshots()->site_series( 4501, '2026-09-01' );
		$this->assertSame( [ gmdate( 'Y-m-d', self::NOW ) ], array_column( $series, 'day' ) );
		$this->assertSame( 8, $series[0]['content_count'] );
	}

	public function test_old_snapshots_and_events_are_purged_with_the_retention_of_the_settings(): void {
		$network = get_current_network_id();
		$this->make_record(
			4502,
			[
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);
		$this->plugin()->snapshots()->capture( $network, gmdate( 'Y-m-d', self::NOW - 400 * DAY_IN_SECONDS ) );
		$this->plugin()->events()->insert(
			[
				[
					'network_id' => $network,
					'site_id'    => 4502,
					'type'       => 'site_created',
					'subject'    => 'old.example/',
					'meta'       => [],
					'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 100 * DAY_IN_SECONDS ),
				],
				[
					'network_id' => $network,
					'site_id'    => 4502,
					'type'       => 'theme_switched',
					'subject'    => 'recent',
					'meta'       => [],
					'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 5 * DAY_IN_SECONDS ),
				],
			]
		);

		$this->plugin()->history()->daily( self::NOW );

		$this->assertSame( [ gmdate( 'Y-m-d', self::NOW ) ], array_column( $this->plugin()->snapshots()->site_series( 4502, '2000-01-01' ), 'day' ) );
		$subjects = array_column(
			$this->plugin()->events()->query(
				[
					'network_id' => $network,
					'since'      => null,
					'types'      => [],
					'site_id'    => 4502,
					'page'       => 1,
					'per_page'   => 20,
				]
			)['items'],
			'subject'
		);
		$this->assertSame( [ 'recent' ], $subjects );

		$this->plugin()->settings()->update( [ 'retention' => [ 'events_days' => 2 ] ] );
		$this->plugin()->history()->daily( self::NOW );
		$this->assertSame(
			0,
			$this->plugin()->events()->query(
				[
					'network_id' => $network,
					'since'      => null,
					'types'      => [],
					'site_id'    => 4502,
					'page'       => 1,
					'per_page'   => 20,
				]
			)['total']
		);
	}

	public function test_it_runs_on_the_daily_task_after_the_queue(): void {
		$this->assertSame( 20, has_action( Queue::HOOK_DAILY, [ $this->plugin()->history(), 'daily' ] ) );

		// WordPress passe un argument vide aux hooks : ni erreur de type, ni erreur signalée.
		$reported = 0;
		$count    = static function () use ( &$reported ): void {
			++$reported;
		};
		add_action( 'msradar_error', $count );
		try {
			do_action( Queue::HOOK_DAILY );
		} finally {
			remove_action( 'msradar_error', $count );
		}
		$this->assertSame( 0, $reported );
	}

	public function test_a_storage_failure_is_reported_and_never_thrown(): void {
		global $wpdb;
		$reported = [];
		$report   = static function ( string $context ) use ( &$reported ): void {
			$reported[] = $context;
		};
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'msradar_snapshots' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		add_action( 'msradar_error', $report );
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->plugin()->history()->daily( self::NOW );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break );
			remove_action( 'msradar_error', $report );
		}

		$this->assertSame( [ History::class . '::daily' ], $reported );
	}
}
