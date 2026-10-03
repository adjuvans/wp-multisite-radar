<?php
namespace MultisiteRadar\Tests\Cli;

use MultisiteRadar\Cli\Pages;
use MultisiteRadar\Tests\TestCase;

final class PagesTest extends TestCase {

	public function test_reads_every_page_until_the_total_is_reached(): void {
		$all   = range( 1, 250 );
		$calls = [];
		$items = Pages::collect(
			static function ( int $page, int $per_page ) use ( $all, &$calls ): array {
				$calls[] = [ $page, $per_page ];
				return [
					'items' => array_slice( $all, ( $page - 1 ) * $per_page, $per_page ),
					'total' => count( $all ),
				];
			}
		);

		$this->assertSame( $all, $items );
		$this->assertSame( [ [ 1, 100 ], [ 2, 100 ], [ 3, 100 ] ], $calls );
	}

	public function test_stops_on_an_empty_page_when_items_disappeared_between_reads(): void {
		$calls = 0;
		$items = Pages::collect(
			static function ( int $page ) use ( &$calls ): array {
				++$calls;
				return [
					'items' => 1 === $page ? [ 'a' ] : [],
					'total' => 5,
				];
			}
		);

		$this->assertSame( [ 'a' ], $items );
		$this->assertSame( 2, $calls );
	}

	public function test_a_failed_read_is_passed_on(): void {
		$this->expectException( \RuntimeException::class );
		Pages::collect(
			static function (): array {
				throw new \RuntimeException( 'read failed' );
			}
		);
	}
}
