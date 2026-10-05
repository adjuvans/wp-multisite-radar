<?php
namespace MultisiteRadar\Tests\Storage;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Storage\AuthorsRepository;
use MultisiteRadar\Tests\TestCase;

final class AuthorsRepositoryTest extends TestCase {

	private function authors(): AuthorsRepository {
		return $this->plugin()->authors();
	}

	public function test_replaces_the_counts_of_a_site_and_marks_it_analysed(): void {
		$site = self::factory()->blog->create();

		$this->authors()->replace_for_site( $site, [ 7 => 3, 9 => 1 ] );
		$this->authors()->replace_for_site( $site, [ 7 => 2, 0 => 5, 11 => 0, -4 => 2 ] );

		$this->assertSame( [ $site => 2 ], $this->authors()->for_user( 7 ) );
		$this->assertSame( [], $this->authors()->for_user( 9 ) );
		$this->assertSame( [ 7 => 2 ], $this->authors()->totals( [ 7, 9, 11 ] ) );
		$this->assertTrue( $this->authors()->is_analysed( $site ) );
		$this->assertSame( [ $site => true ], $this->authors()->analysed_among( [ $site, $site + 1000 ] ) );
	}

	public function test_totals_add_the_sites_and_ignore_deleted_ones(): void {
		$first  = self::factory()->blog->create();
		$second = self::factory()->blog->create();
		$this->authors()->replace_for_site( $first, [ 7 => 2 ] );
		$this->authors()->replace_for_site( $second, [ 7 => 5 ] );
		$this->authors()->replace_for_site( 987654, [ 7 => 40 ] );

		$this->assertSame( [ 7 => 7 ], $this->authors()->totals( [ 7 ] ) );
		$this->assertSame( [], $this->authors()->totals( [] ) );
	}

	public function test_deleting_a_site_forgets_it(): void {
		$site = self::factory()->blog->create();
		$this->authors()->replace_for_site( $site, [ 7 => 2 ] );

		$this->authors()->delete_for_site( $site );

		$this->assertSame( [], $this->authors()->for_user( 7 ) );
		$this->assertFalse( $this->authors()->is_analysed( $site ) );
	}

	public function test_is_empty_until_a_first_site_is_analysed(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::authors_table() ) );
		$this->assertTrue( $this->authors()->is_empty() );

		$this->authors()->replace_for_site( self::factory()->blog->create(), [] );

		$this->assertFalse( $this->authors()->is_empty() );
	}

	public function test_a_failed_write_leaves_the_site_not_analysed_and_throws(): void {
		global $wpdb;
		$site = self::factory()->blog->create();
		$this->authors()->replace_for_site( $site, [ 7 => 1 ] );
		// Seule l'écriture d'un auteur (user_id > 0) échoue ; la ligne témoin passerait.
		$pattern = '/^INSERT INTO `' . preg_quote( Schema::authors_table(), '/' ) . '` .* VALUES \\(\\d+, [1-9]/';
		$break   = static function ( string $query ) use ( $pattern ): string {
			return 1 === preg_match( $pattern, $query ) ? 'INSERT INTO msradar_missing_table VALUES (1)' : $query;
		};
		$before  = wp_cache_get_last_changed( AuthorsRepository::CACHE_GROUP );
		usleep( 2 );

		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		$thrown = null;
		try {
			$this->authors()->replace_for_site( $site, [ 7 => 3 ] );
		} catch ( \RuntimeException $error ) {
			$thrown = $error;
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertInstanceOf( \RuntimeException::class, $thrown );
		$this->assertFalse( $this->authors()->is_analysed( $site ), 'A site whose counts are partial is not analysed.' );
		$this->assertNotSame( $before, wp_cache_get_last_changed( AuthorsRepository::CACHE_GROUP ), 'The lists built before the failure are not served again.' );
	}

	public function test_every_write_renews_the_cache_generation(): void {
		$site   = self::factory()->blog->create();
		$before = wp_cache_get_last_changed( AuthorsRepository::CACHE_GROUP );
		usleep( 2 );

		$this->authors()->replace_for_site( $site, [ 7 => 1 ] );

		$this->assertNotSame( $before, wp_cache_get_last_changed( AuthorsRepository::CACHE_GROUP ) );
	}
}
