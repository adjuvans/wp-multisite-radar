<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Query\InventoryList;
use MultisiteRadar\Tests\TestCase;

final class InventoryListTest extends TestCase {

	private const STATUSES = [ 'network', 'local', 'unused', 'missing' ];

	private static function items(): array {
		return [
			[
				'id'             => 'b/b.php',
				'name'           => 'Bravo',
				'sites_count'    => 2,
				'status'         => 'unused',
				'version'        => '1.10.0',
				'update_version' => null,
			],
			[
				'id'             => 'a/a.php',
				'name'           => 'alpha',
				'sites_count'    => 5,
				'status'         => 'network',
				'version'        => '1.9.2',
				'update_version' => '2.0.0',
			],
			[
				'id'             => 'c/c.php',
				'name'           => 'Charlie',
				'sites_count'    => 0,
				'status'         => 'missing',
				'version'        => '',
				'update_version' => null,
			],
			[
				'id'             => 'd/d.php',
				'name'           => 'Delta',
				'sites_count'    => 1,
				'status'         => 'local',
				'version'        => '1.10.0',
				'update_version' => '1.11.0',
			],
		];
	}

	private static function names( string $orderby, string $order ): array {
		return array_column( InventoryList::sort( self::items(), $orderby, $order, self::STATUSES ), 'name' );
	}

	public function test_sorts_by_status_in_the_given_order(): void {
		$this->assertSame( [ 'alpha', 'Delta', 'Bravo', 'Charlie' ], self::names( 'status', 'asc' ) );
		$this->assertSame( [ 'Charlie', 'Bravo', 'Delta', 'alpha' ], self::names( 'status', 'desc' ) );
	}

	public function test_sorts_versions_numerically_then_by_name(): void {
		// Version vide < 1.9.2 < 1.10.0 ; Bravo et Delta à égalité, départagés par le nom.
		$this->assertSame( [ 'Charlie', 'alpha', 'Bravo', 'Delta' ], self::names( 'version', 'asc' ) );
	}

	public function test_sorts_by_update_without_update_first(): void {
		$this->assertSame( [ 'Bravo', 'Charlie', 'alpha', 'Delta' ], self::names( 'update_version', 'asc' ) );
		$this->assertSame( [ 'alpha', 'Delta', 'Bravo', 'Charlie' ], self::names( 'update_version', 'desc' ) );
	}

	public function test_name_and_sites_count_keep_their_order(): void {
		$this->assertSame( [ 'alpha', 'Bravo', 'Charlie', 'Delta' ], self::names( 'name', 'asc' ) );
		$this->assertSame( [ 'alpha', 'Bravo', 'Delta', 'Charlie' ], self::names( 'sites_count', 'desc' ) );
	}

	public function test_an_unknown_status_sorts_last(): void {
		$items              = self::items();
		$items[0]['status'] = 'bogus';

		$sorted = InventoryList::sort( $items, 'status', 'asc', self::STATUSES );

		$this->assertSame( 'Bravo', end( $sorted )['name'] );
	}
}
