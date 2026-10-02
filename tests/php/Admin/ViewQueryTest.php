<?php
namespace MultisiteRadar\Tests\Admin;

use MultisiteRadar\Admin\ViewQuery;
use MultisiteRadar\Tests\TestCase;

final class ViewQueryTest extends TestCase {

	public static function cases(): array {
		$cases = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/view-queries.json' ), true );
		$out   = [];
		foreach ( $cases as $case ) {
			$out[ $case['name'] ] = [ $case['view'], $case['query'], $case['prefs'], $case['args'] ];
		}
		return $out;
	}

	/**
	 * @dataProvider cases
	 */
	public function test_matches_the_cases_shared_with_the_client( string $view, array $query, array $prefs, array $args ): void {
		$builders = [
			'sites'   => [ ViewQuery::class, 'sites' ],
			'alerts'  => [ ViewQuery::class, 'alerts' ],
			'plugins' => [ ViewQuery::class, 'plugins' ],
			'themes'  => [ ViewQuery::class, 'themes' ],
			'users'   => [ ViewQuery::class, 'users' ],
		];
		$this->assertSame( $args, call_user_func( $builders[ $view ], $query, $prefs ) );
	}

	public function test_paths_skip_empty_values_like_the_client(): void {
		$this->assertSame(
			'/multisite-radar/v1/users?page=1',
			ViewQuery::path(
				'/users',
				[
					'page'       => 1,
					'search'     => '',
					'membership' => null,
				]
			)
		);
	}

	public function test_paths_sort_keys_and_encode_values(): void {
		$this->assertSame( '/multisite-radar/v1/preferences', ViewQuery::path( '/preferences' ) );
		$this->assertSame(
			'/multisite-radar/v1/sites?page=1&search=O%27Brien%20%2B%20100%25%20%C3%A9t%C3%A9',
			ViewQuery::path(
				'/sites',
				[
					'search' => "O'Brien + 100% été",
					'page'   => 1,
				]
			)
		);
	}

	public function test_site_id(): void {
		$this->assertSame( 12, ViewQuery::site_id( [ 'site' => '12' ] ) );
		$this->assertSame( 0, ViewQuery::site_id( [ 'site' => '12abc' ] ) );
		$this->assertSame( 0, ViewQuery::site_id( [] ) );
		$this->assertSame( 0, ViewQuery::site_id( [ 'site' => "12\n" ] ) );
	}
}
