<?php
namespace MultisiteRadar\Tests\Storage;

use MultisiteRadar\Storage\EventsRepository;
use MultisiteRadar\Tests\TestCase;

final class EventsRepositoryTest extends TestCase {

	private const NETWORK = 77;

	private function event( string $type, int $site_id, string $created_at, array $overrides = [] ): array {
		return array_merge(
			[
				'network_id' => self::NETWORK,
				'site_id'    => $site_id,
				'type'       => $type,
				'subject'    => 'akismet/akismet.php',
				'meta'       => [],
				'created_at' => $created_at,
			],
			$overrides
		);
	}

	private function repository(): EventsRepository {
		return $this->plugin()->events();
	}

	public function test_events_are_read_newest_first_with_their_meta(): void {
		$this->repository()->insert(
			[
				$this->event( 'plugin_activated', 5, '2026-09-01 10:00:00' ),
				$this->event( 'theme_switched', 5, '2026-09-02 10:00:00', [ 'subject' => 'child', 'meta' => [ 'from' => 'parent' ] ] ),
			]
		);

		$result = $this->repository()->query(
			[
				'network_id' => self::NETWORK,
				'since'      => null,
				'types'      => [],
				'site_id'    => 0,
				'page'       => 1,
				'per_page'   => 20,
			]
		);

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( [ 'theme_switched', 'plugin_activated' ], array_column( $result['items'], 'type' ) );
		$this->assertSame( [ 'from' => 'parent' ], $result['items'][0]['meta'] );
		$this->assertSame( [], $result['items'][1]['meta'] );
		$this->assertSame( 5, $result['items'][0]['site_id'] );
		$this->assertSame( '2026-09-02 10:00:00', $result['items'][0]['created_at'] );
	}

	public function test_filters_by_date_type_site_and_network_and_paginates(): void {
		$this->repository()->insert(
			[
				$this->event( 'plugin_activated', 5, '2026-08-01 10:00:00' ),
				$this->event( 'alert_raised', 5, '2026-09-01 10:00:00', [ 'subject' => 'inactive' ] ),
				$this->event( 'alert_raised', 6, '2026-09-02 10:00:00', [ 'subject' => 'inactive' ] ),
				$this->event( 'alert_raised', 7, '2026-09-03 10:00:00', [ 'network_id' => 78 ] ),
			]
		);
		$base = [
			'network_id' => self::NETWORK,
			'since'      => null,
			'types'      => [],
			'site_id'    => 0,
			'page'       => 1,
			'per_page'   => 20,
		];

		$this->assertSame( 2, $this->repository()->query( array_merge( $base, [ 'since' => '2026-08-15 00:00:00' ] ) )['total'] );
		$this->assertSame( 2, $this->repository()->query( array_merge( $base, [ 'types' => [ 'alert_raised', 'not_a_type' ] ] ) )['total'] );
		$this->assertSame( [ 5, 5 ], array_column( $this->repository()->query( array_merge( $base, [ 'site_id' => 5 ] ) )['items'], 'site_id' ) );

		$page = $this->repository()->query( array_merge( $base, [ 'per_page' => 2, 'page' => 2 ] ) );
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( [ 'plugin_activated' ], array_column( $page['items'], 'type' ) );
	}

	public function test_counts_by_type_and_purge_stay_in_their_network(): void {
		$this->repository()->insert(
			[
				$this->event( 'alert_raised', 5, '2026-06-01 10:00:00' ),
				$this->event( 'alert_raised', 5, '2026-09-01 10:00:00' ),
				$this->event( 'alert_resolved', 5, '2026-09-02 10:00:00' ),
				$this->event( 'alert_raised', 9, '2026-06-01 10:00:00', [ 'network_id' => 78 ] ),
			]
		);

		$this->assertSame(
			[
				'alert_raised'   => 1,
				'alert_resolved' => 1,
			],
			$this->repository()->counts( self::NETWORK, '2026-08-01 00:00:00' )
		);

		$this->assertSame( 1, $this->repository()->purge( self::NETWORK, '2026-07-01 00:00:00' ) );
		$this->assertSame(
			[
				'alert_raised'   => 1,
				'alert_resolved' => 1,
			],
			$this->repository()->counts( self::NETWORK, '2026-01-01 00:00:00' ),
			'The event of 2026-06-01 is purged.'
		);
		$this->assertSame( [ 'alert_raised' => 1 ], $this->repository()->counts( 78, '2026-01-01 00:00:00' ), 'The other network keeps its events.' );
	}

	public function test_a_long_subject_is_cut_to_the_column_size(): void {
		$this->repository()->insert( [ $this->event( 'site_created', 5, '2026-09-01 10:00:00', [ 'subject' => str_repeat( 'é', 300 ) ] ) ] );

		$items = $this->repository()->query(
			[
				'network_id' => self::NETWORK,
				'since'      => null,
				'types'      => [],
				'site_id'    => 0,
				'page'       => 1,
				'per_page'   => 1,
			]
		)['items'];

		$this->assertSame( 191, mb_strlen( $items[0]['subject'] ) );
	}
}
