<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Tests\TestCase;

final class SitesQueryTest extends TestCase {

	private SitesQuery $query;

	public function set_up(): void {
		parent::set_up();
		$this->query = $this->plugin()->sites_query();
		$scanned     = '2026-09-01 00:00:00';

		$this->make_record( 101, [ 'name' => 'Alpha', 'url' => 'https://alpha.test/', 'users_count' => 3, 'theme_stylesheet' => 'astra', 'theme_template' => 'astra', 'last_activity_gmt' => '2026-08-30 10:00:00', 'scanned_at' => $scanned, 'registry_status' => 'fresh' ] );
		$this->make_record( 102, [ 'name' => 'Beta 100%', 'url' => 'https://beta.test/', 'users_count' => 0, 'alert_level' => 3, 'alerts_count' => 1, 'alert_rules' => ',no_users,', 'theme_stylesheet' => 'child', 'theme_template' => 'astra', 'last_activity_gmt' => '2024-01-01 00:00:00', 'scanned_at' => $scanned, 'registry_status' => 'stale', 'is_archived' => true ] );
		$this->make_record( 103, [ 'name' => 'Gamma_x', 'url' => 'https://gamma.test/', 'users_count' => 12, 'alert_level' => 2, 'alerts_count' => 1, 'alert_rules' => ',inactive,', 'theme_stylesheet' => 'tt5', 'theme_template' => 'tt5', 'scanned_at' => $scanned, 'is_public' => false ] );
		$this->make_record( 104, [ 'url' => 'https://pending.test/' ] );
		$this->plugin()->extensions()->replace_for_site( 101, [ 'acme/acme.php' ], 'astra', 'astra' );
	}

	private function ids( array $args ): array {
		return wp_list_pluck( $this->query->list( $args )['items'], 'id' );
	}

	public function test_lists_the_current_network_sorted_by_name(): void {
		$this->make_record( 105, [ 'name' => 'Other network', 'network_id' => 2 ] );

		$result = $this->query->list( [] );

		$this->assertSame( 4, $result['total'] );
		$this->assertSame( [ 104, 101, 102, 103 ], wp_list_pluck( $result['items'], 'id' ) );
	}

	public function test_search_treats_sql_wildcards_literally(): void {
		$this->assertSame( [ 102 ], $this->ids( [ 'search' => '%' ] ) );
		$this->assertSame( [ 103 ], $this->ids( [ 'search' => '_' ] ) );
		$this->assertSame( [ 101 ], $this->ids( [ 'search' => 'alpha.test' ] ) );
	}

	public function test_filters(): void {
		update_site_option( 'active_sitewide_plugins', [ 'net/net.php' => time() ] );

		$this->assertSame( [ 102 ], $this->ids( [ 'alert_level' => [ 'error' ] ] ) );
		$this->assertSame( [ 102, 103 ], $this->ids( [ 'alert_level' => [ 'warning', 'error' ] ] ) );
		$this->assertSame( [ 102 ], $this->ids( [ 'status' => [ 'archived' ] ] ) );
		$this->assertSame( [ 103 ], $this->ids( [ 'status' => [ 'private' ] ] ) );
		$this->assertSame( [ 104, 101 ], $this->ids( [ 'status' => [ 'public' ] ] ) );
		$this->assertSame( [ 101, 102 ], $this->ids( [ 'theme' => 'astra' ] ) );
		$this->assertSame( [ 101 ], $this->ids( [ 'plugin' => 'acme/acme.php' ] ) );
		$this->assertSame( [ 104, 101, 102, 103 ], $this->ids( [ 'plugin' => 'net/net.php' ] ), 'A network-active plugin is used everywhere.' );
		$this->assertSame( [ 102 ], $this->ids( [ 'has_users' => false ] ), 'Unscanned sites are not counted as empty.' );
		$this->assertSame( [ 101, 103 ], $this->ids( [ 'has_users' => true ] ) );
		$this->assertSame( [ 102, 103 ], $this->ids( [ 'inactive_since' => '2025-01-01 00:00:00' ] ) );
		$this->assertSame( [ 104, 102, 103 ], $this->ids( [ 'registry_status' => [ 'stale', 'missing' ] ] ) );
		$this->assertSame( [ 102 ], $this->ids( [ 'rule' => 'no_users' ] ) );
	}

	public function test_sorting_and_pagination(): void {
		$this->assertSame( [ 103, 101, 102, 104 ], $this->ids( [ 'orderby' => 'users_count', 'order' => 'desc' ] ) );
		$this->assertSame( [ 104, 101, 102, 103 ], $this->ids( [ 'orderby' => 'bogus' ] ), 'Unknown orderby falls back to name.' );

		$page = $this->query->list( [ 'per_page' => 2, 'page' => 2 ] );
		$this->assertSame( [ 102, 103 ], wp_list_pluck( $page['items'], 'id' ) );
		$this->assertSame( 4, $page['total'] );

		$beyond = $this->query->list( [ 'page' => 99 ] );
		$this->assertSame( [], $beyond['items'] );
		$this->assertSame( 4, $beyond['total'] );

		$this->assertCount( 4, $this->query->list( [ 'per_page' => 500 ] )['items'], 'per_page is clamped to 100, not rejected, at this layer.' );
	}

	public function test_summary_shape(): void {
		$items = $this->query->list( [ 'search' => 'Beta' ] )['items'];
		$beta  = $items[0];

		$this->assertSame( 'error', $beta['alert_level'] );
		$this->assertSame( [ 'no_users' ], $beta['alert_rules'] );
		$this->assertTrue( $beta['status']['archived'] );
		$this->assertSame( [ 'stylesheet' => 'child', 'template' => 'astra' ], $beta['theme'] );
		$this->assertSame( '2024-01-01T00:00:00', $beta['last_activity_gmt'] );
		$this->assertSame( '2026-09-01T00:00:00', $beta['scanned_at_gmt'] );
		$this->assertSame( 'https://beta.test/wp-admin/', $beta['admin_url'] );
		$this->assertFalse( $beta['pending'] );
		$this->assertTrue( $this->query->list( [ 'search' => 'pending' ] )['items'][0]['pending'] );
	}

	public function test_get_returns_details_and_applies_the_plugin_filter(): void {
		$this->make_record(
			201,
			[
				'name'             => 'Detail',
				'url'              => 'https://detail.test/',
				'scanned_at'       => '2026-09-01 00:00:00',
				'theme_stylesheet' => 'missing-theme',
				'theme_template'   => 'missing-theme',
				'data'             => [
					'post_types'   => [
						[ 'name' => 'post', 'builtin' => true, 'origin' => [ 'kind' => 'core', 'slug' => '' ] ],
						[ 'name' => 'event', 'builtin' => false, 'origin' => [ 'kind' => 'plugin', 'slug' => 'acme' ] ],
						[ 'name' => 'book', 'builtin' => false, 'origin' => [ 'kind' => 'plugin', 'slug' => 'other' ] ],
					],
					'taxonomies'   => [],
					'alerts'       => [ [ 'rule' => 'inactive', 'severity' => 'warning', 'args' => [ 'months' => 8 ] ] ],
					'last_content' => [ 'id' => 5, 'type' => 'post', 'title' => 'Hi', 'date_gmt' => '2026-01-02 03:04:05' ],
					'options'      => [ 'siteurl' => 'https://detail.test' ],
				],
			]
		);
		$this->plugin()->extensions()->replace_for_site( 201, [ 'acme/acme.php' ], 'missing-theme', 'missing-theme' );

		$detail = $this->query->get( 201 );

		$this->assertSame( 'Inactive for 8 months', $detail['alerts'][0]['message'] );
		$this->assertSame( '2026-01-02T03:04:05', $detail['last_content']['date_gmt'] );
		$this->assertSame( 'https://detail.test/wp-admin/', $detail['admin_url'] );
		$this->assertSame( [ 'file' => 'acme/acme.php', 'name' => 'acme/acme.php', 'version' => '', 'installed' => false ], $detail['extensions']['plugins_local'][0] );
		$this->assertFalse( $detail['extensions']['theme']['installed'] );
		$this->assertCount( 3, $detail['post_types'] );

		$this->plugin()->settings()->update( [ 'scan' => [ 'analysis_plugins' => [ 'acme' ] ] ] );
		$this->assertSame( [ 'post', 'event' ], wp_list_pluck( $this->query->get( 201 )['post_types'], 'name' ) );

		$this->assertNull( $detail['scan_error'] );
		$this->make_record( 203, [ 'data' => [ 'scan_error' => [ 'message' => 'Boom <b>', 'at_gmt' => '2026-09-02 03:04:05', 'extra' => 'x' ] ] ] );
		$this->assertSame( [ 'message' => 'Boom <b>', 'at_gmt' => '2026-09-02T03:04:05' ], $this->query->get( 203 )['scan_error'] );

		$this->assertNull( $this->query->get( 999999 ) );
		$this->make_record( 202, [ 'network_id' => 2 ] );
		$this->assertNull( $this->query->get( 202 ), 'Sites of another network are hidden.' );
	}

	public function test_summary_makes_urls_absolute_and_names_non_empty_for_unscanned_sites(): void {
		$this->make_record( 205, [ 'url' => 'example.test/sub/', 'name' => '' ] );

		$summary = $this->query->get( 205 );

		$this->assertMatchesRegularExpression( '#^https?://example\.test/sub/$#', $summary['url'] );
		$this->assertMatchesRegularExpression( '#^https?://example\.test/sub/wp-admin/$#', $summary['admin_url'] );
		$this->assertSame( 'Site #205', $summary['name'] );
	}

	public function test_admin_links_use_the_wordpress_address_when_it_differs_from_home(): void {
		$this->make_record(
			206,
			[
				'name'       => 'Sub',
				'url'        => 'https://home.test/',
				'siteurl'    => 'https://home.test/wp/',
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);

		$items = $this->query->list( [ 'search' => 'home.test' ] )['items'];

		$this->assertSame( [ 206 ], wp_list_pluck( $items, 'id' ) );
		$this->assertSame( 'https://home.test/', $items[0]['url'] );
		$this->assertSame( 'https://home.test/wp/wp-admin/', $items[0]['admin_url'] );
	}

	public function test_alert_levels_are_reported_by_name(): void {
		$this->assertSame( 'error', $this->query->get( 102 )['alert_level'] );
		$this->assertSame( 'none', $this->query->get( 101 )['alert_level'] );
		$this->assertSame( [ 104, 101 ], $this->ids( [ 'alert_level' => [ 'none', 'bogus' ] ] ) );
	}
}
