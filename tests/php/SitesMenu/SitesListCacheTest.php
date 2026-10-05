<?php
namespace MultisiteRadar\Tests\SitesMenu;

use MultisiteRadar\SitesMenu\SitesListCache;
use MultisiteRadar\Tests\TestCase;

final class SitesListCacheTest extends TestCase {

	public function test_builds_the_public_sites_of_the_network_with_their_name_and_home_url(): void {
		$public   = self::factory()->blog->create( [ 'title' => 'Blog RH' ] );
		$archived = self::factory()->blog->create();
		update_blog_status( $archived, 'archived', '1' );
		$private = self::factory()->blog->create( [ 'public' => 0 ] );
		$other   = self::factory()->blog->create( [ 'network_id' => self::factory()->network->create() ] );

		$sites = ( new SitesListCache() )->build( get_current_network_id() );
		$ids   = wp_list_pluck( $sites, 'id' );

		$this->assertContains( $public, $ids );
		$this->assertNotContains( $archived, $ids );
		$this->assertNotContains( $private, $ids );
		$this->assertNotContains( $other, $ids );
		$entry = $sites[ array_search( $public, $ids, true ) ];
		$this->assertSame( 'Blog RH', $entry['name'] );
		$this->assertSame( get_blog_option( $public, 'home' ), $entry['url'] );
		$this->assertSame( [ 'id', 'name', 'url', 'registered' ], array_keys( $entry ) );
	}

	public function test_names_are_plain_text(): void {
		$site_id = self::factory()->blog->create( [ 'title' => "L'atelier R&D" ] );

		$sites = ( new SitesListCache() )->build( get_current_network_id() );

		$this->assertSame( "L'atelier R&D", $sites[ array_search( $site_id, wp_list_pluck( $sites, 'id' ), true ) ]['name'] );
	}

	public function test_a_site_whose_tables_are_missing_is_skipped(): void {
		global $wpdb;
		$healthy = self::factory()->blog->create( [ 'title' => 'Healthy' ] );
		$wpdb->insert(
			$wpdb->blogs,
			[
				'blog_id'      => 99999,
				'site_id'      => get_current_network_id(),
				'domain'       => 'ghost.test',
				'path'         => '/',
				'registered'   => '2026-01-01 00:00:00',
				'last_updated' => '2026-01-01 00:00:00',
				'public'       => 1,
				'archived'     => 0,
				'mature'       => 0,
				'spam'         => 0,
				'deleted'      => 0,
				'lang_id'      => 0,
			]
		);

		$ids = wp_list_pluck( ( new SitesListCache() )->build( get_current_network_id() ), 'id' );

		$this->assertContains( $healthy, $ids );
		$this->assertNotContains( 99999, $ids );
	}

	public function test_get_is_cached_per_network_and_site_changes_flush_it(): void {
		$cache   = $this->plugin()->sites_list_cache();
		$network = get_current_network_id();
		$site    = self::factory()->blog->create( [ 'title' => 'Before' ] );
		$cache->flush( $network );

		$this->assertContains( 'Before', wp_list_pluck( $cache->get(), 'name' ) );
		$this->assertIsArray( get_site_transient( SitesListCache::name( $network ) ) );

		update_blog_option( $site, 'blogname', 'After' );
		$this->assertFalse( get_site_transient( SitesListCache::name( $network ) ), 'Renaming a site flushes the list.' );
		$this->assertContains( 'After', wp_list_pluck( $cache->get(), 'name' ) );

		update_blog_status( $site, 'archived', '1' );
		$this->assertNotContains( $site, wp_list_pluck( $cache->get(), 'id' ), 'Archiving a site removes it at once.' );

		$new = self::factory()->blog->create( [ 'title' => 'Newcomer' ] );
		$this->assertContains( $new, wp_list_pluck( $cache->get(), 'id' ) );
	}

	public function test_a_failing_sites_read_is_reported_and_not_cached(): void {
		$cache   = $this->plugin()->sites_list_cache();
		$network = get_current_network_id();
		$site    = self::factory()->blog->create( [ 'title' => 'Survivor' ] );
		$cache->flush( $network );

		$errors = [];
		$on_err = static function ( $context, $error ) use ( &$errors ): void {
			$errors[] = [ $context, $error ];
		};
		$break  = static function ( $query ) {
			return false !== strpos( $query, 'SELECT blog_id, domain, path, registered' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		add_action( 'msradar_error', $on_err, 10, 2 );
		add_filter( 'query', $break );
		try {
			$result = $cache->get();
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $quiet );
			remove_action( 'msradar_error', $on_err, 10 );
		}

		$this->assertSame( [], $result );
		$this->assertCount( 1, $errors );
		$this->assertInstanceOf( \RuntimeException::class, $errors[0][1] );
		$this->assertFalse( get_site_transient( SitesListCache::name( $network ) ), 'A failed build is not cached.' );
		$this->assertContains( $site, wp_list_pluck( $cache->get(), 'id' ) );
	}

	public function test_sites_are_read_in_chunks_of_the_given_size(): void {
		$titles = [ 'Chunk A', 'Chunk B', 'Chunk C', 'Chunk D', 'Chunk E' ];
		foreach ( $titles as $title ) {
			self::factory()->blog->create( [ 'title' => $title ] );
		}
		$queries = 0;
		$count   = static function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, "option_name IN ('blogname', 'home')" ) ) {
				++$queries;
			}
			return $query;
		};
		add_filter( 'query', $count );
		try {
			$sites = ( new SitesListCache( 2 ) )->build( get_current_network_id() );
		} finally {
			remove_filter( 'query', $count );
		}

		$this->assertSame( (int) ceil( count( $sites ) / 2 ), $queries, 'One query per chunk of 2 sites.' );
		$this->assertSame( ( new SitesListCache() )->build( get_current_network_id() ), $sites );
		foreach ( $titles as $title ) {
			$this->assertContains( $title, wp_list_pluck( $sites, 'name' ) );
		}
	}
}
