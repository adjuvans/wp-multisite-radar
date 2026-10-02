<?php
namespace MultisiteRadar\Tests\Storage;

use MultisiteRadar\Tests\TestCase;

final class ExtensionsRepositoryTest extends TestCase {

	public function test_replace_for_site_stores_local_plugins_and_theme_roles(): void {
		$extensions = $this->plugin()->extensions();

		$extensions->replace_for_site( 5, [ 'hello.php', 'acme/acme.php', 'acme/acme.php' ], 'child', 'parent' );

		$this->assertSame(
			[
				[ 'type' => 'plugin', 'slug' => 'acme/acme.php', 'role' => 'local' ],
				[ 'type' => 'plugin', 'slug' => 'hello.php', 'role' => 'local' ],
				[ 'type' => 'theme', 'slug' => 'child', 'role' => 'active' ],
				[ 'type' => 'theme', 'slug' => 'parent', 'role' => 'parent' ],
			],
			$extensions->for_site( 5 )
		);

		$extensions->replace_for_site( 5, [], 'twentytwentyfive', 'twentytwentyfive' );
		$this->assertSame( [ [ 'type' => 'theme', 'slug' => 'twentytwentyfive', 'role' => 'active' ] ], $extensions->for_site( 5 ) );

		$extensions->delete_for_site( 5 );
		$this->assertSame( [], $extensions->for_site( 5 ) );
	}

	/**
	 * @dataProvider failing_statements
	 */
	public function test_a_failed_write_throws( string $prefix ): void {
		global $wpdb;
		$guard = static fn ( string $query ): string => 0 === strpos( $query, $prefix . ' `' . $wpdb->base_prefix . 'msradar_site_extensions`' ) ? 'SELECT * FROM msradar_no_such_table' : $query;
		add_filter( 'query', $guard );
		$previous = $wpdb->suppress_errors( true );
		$this->expectException( \RuntimeException::class );

		try {
			$this->plugin()->extensions()->replace_for_site( 5, [ 'a.php' ], '', '' );
		} finally {
			$wpdb->suppress_errors( $previous );
			remove_filter( 'query', $guard );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function failing_statements(): array {
		return [
			'delete' => [ 'DELETE FROM' ],
			'insert' => [ 'INSERT INTO' ],
		];
	}

	public function test_plugin_counts_cover_the_analysed_sites_of_one_network(): void {
		$this->make_record( 931 );
		$this->make_record( 932 );
		$this->make_record( 933, [ 'network_id' => 2 ] );
		$repository = $this->plugin()->extensions();
		$repository->replace_for_site( 931, [ 'alpha/alpha.php', 'beta.php' ], '', '' );
		$repository->replace_for_site( 932, [ 'alpha/alpha.php' ], '', '' );
		$repository->replace_for_site( 933, [ 'alpha/alpha.php' ], '', '' );

		$this->assertSame(
			[
				'alpha/alpha.php' => 2,
				'beta.php'        => 1,
			],
			$repository->plugin_counts( get_current_network_id() )
		);
		$this->assertSame( [ 'alpha/alpha.php' => 1 ], $repository->plugin_counts( 2 ) );
	}

	public function test_a_failed_count_read_throws(): void {
		global $wpdb;
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'GROUP BY e.slug' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$this->expectException( \RuntimeException::class );
			$this->plugin()->extensions()->plugin_counts( 1 );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}
	}
}
