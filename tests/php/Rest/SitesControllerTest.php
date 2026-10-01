<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Tests\RestTestCase;

final class SitesControllerTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$scanned = '2026-09-01 00:00:00';
		$this->make_record( 101, [ 'name' => 'Alpha', 'users_count' => 3, 'last_activity_gmt' => '2026-08-30 10:00:00', 'scanned_at' => $scanned ] );
		$this->make_record( 102, [ 'name' => 'Beta', 'users_count' => 0, 'alert_level' => 3, 'alerts_count' => 1, 'alert_rules' => ',no_users,', 'last_activity_gmt' => '2024-01-01 00:00:00', 'scanned_at' => $scanned ] );
	}

	public function test_requires_authentication_and_the_network_capability(): void {
		$this->assertSame( 401, $this->request( 'GET', '/sites' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/sites' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/sites/101' )->get_status() );
	}

	public function test_lists_sites_with_pagination_headers(): void {
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/sites', [ 'per_page' => 1 ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( 'Alpha', $response->get_data()[0]['name'] );
	}

	public function test_filters_are_passed_through(): void {
		$this->login_as_super_admin();

		$this->assertSame( [ 102 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'alert_level' => [ 'error' ] ] )->get_data(), 'id' ) );
		$this->assertSame( [ 102 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'has_users' => 'false' ] )->get_data(), 'id' ) );
		$this->assertSame( [ 102 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'inactive_since' => '2025-01-01T00:00:00' ] )->get_data(), 'id' ) );
		$this->assertSame( [ 102, 101 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'orderby' => 'last_activity', 'order' => 'asc' ] )->get_data(), 'id' ) );
	}

	/**
	 * @dataProvider invalid_params
	 */
	public function test_rejects_invalid_parameters( array $params ): void {
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/sites', $params );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	public static function invalid_params(): array {
		return [
			'per_page above 100'  => [ [ 'per_page' => 500 ] ],
			'unknown orderby'     => [ [ 'orderby' => 'bogus' ] ],
			'unknown alert level' => [ [ 'alert_level' => [ 'fatal' ] ] ],
			'invalid date'        => [ [ 'inactive_since' => 'yesterday' ] ],
		];
	}

	public function test_a_page_beyond_the_last_is_empty_with_the_right_total(): void {
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/sites', [ 'page' => 9 ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
	}

	public function test_search_treats_percent_and_underscore_literally(): void {
		$this->login_as_super_admin();
		$this->make_record( 103, [ 'name' => '100% Cotton_Shop', 'scanned_at' => '2026-09-01 00:00:00' ] );

		$this->assertSame( [ 103 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'search' => '%' ] )->get_data(), 'id' ) );
		$this->assertSame( [ 103 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'search' => 'n_S' ] )->get_data(), 'id' ) );
		$this->assertSame( [], $this->request( 'GET', '/sites', [ 'search' => 'Cotton%Shop' ] )->get_data() );
	}

	public function test_get_item(): void {
		$this->login_as_super_admin();

		$this->assertSame( 'Beta', $this->request( 'GET', '/sites/102' )->get_data()['name'] );

		$missing = $this->request( 'GET', '/sites/999999' );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'msradar_site_not_found', $missing->get_data()['code'] );
	}

	public function test_a_site_created_through_wordpress_is_listed_decoded_and_found_by_apostrophe_and_ampersand_searches(): void {
		$this->login_as_super_admin();
		$site_id = self::factory()->blog->create( [ 'title' => "L'atelier R&D" ] );
		$this->plugin()->runner()->scan_site( $site_id );

		foreach ( [ "L'atelier", 'R&D', "L'atelier R&D" ] as $search ) {
			$items = $this->request( 'GET', '/sites', [ 'search' => $search ] )->get_data();
			$this->assertSame( [ $site_id ], wp_list_pluck( $items, 'id' ), $search );
			$this->assertSame( "L'atelier R&D", $items[0]['name'] );
		}
		$this->assertSame( "L'atelier R&D", $this->request( 'GET', '/sites/' . $site_id )->get_data()['name'] );
	}

	public function test_include_selects_sites(): void {
		$this->login_as_super_admin();

		$this->assertSame( [ 102 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'include' => [ 102, 999 ] ] )->get_data(), 'id' ) );
		$this->assertSame( [ 101, 102 ], wp_list_pluck( $this->request( 'GET', '/sites', [ 'include' => '102,101' ] )->get_data(), 'id' ) );
	}

	public function test_inactive_since_honours_the_utc_offset(): void {
		$this->login_as_super_admin();
		$this->make_record(
			103,
			[
				'name'              => 'Gamma',
				'users_count'       => 1,
				'last_activity_gmt' => '2024-12-31 23:30:00',
				'scanned_at'        => '2026-09-01 00:00:00',
			]
		);

		$ids = wp_list_pluck( $this->request( 'GET', '/sites', [ 'inactive_since' => '2025-01-01T01:00:00+02:00' ] )->get_data(), 'id' );

		$this->assertSame( [ 102 ], $ids, '01:00+02:00 is 23:00 UTC: Gamma was still active at 23:30 UTC.' );
	}

	public function test_empty_maps_are_json_objects(): void {
		$this->login_as_super_admin();

		$json = (string) wp_json_encode( $this->request( 'GET', '/sites/101' )->get_data() );

		$this->assertStringContainsString( '"options":{}', $json );
		$this->assertStringContainsString( '"by_role":{}', $json );
	}

	public function test_a_failed_read_is_a_500_not_an_empty_list(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return 0 === strpos( ltrim( $query ), 'SELECT COUNT(*)' ) && false !== strpos( $query, Schema::sites_table() ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/sites' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'msradar_storage_error', $response->get_data()['code'] );
	}

	public function test_site_users_route(): void {
		$site_id = self::factory()->blog->create();
		// La fabrique ne rattache aucun utilisateur : l'utilisateur 1 (« admin ») devient le seul membre du site.
		add_user_to_blog( $site_id, 1, 'administrator' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', "/sites/{$site_id}/users" )->get_status() );

		$this->login_as_super_admin();
		$response = $this->request( 'GET', "/sites/{$site_id}/users", [ 'per_page' => 1 ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 'admin', $response->get_data()[0]['login'] );

		$this->assertSame( 404, $this->request( 'GET', '/sites/999999/users' )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', "/sites/{$site_id}/users", [ 'orderby' => 'email' ] )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', "/sites/{$site_id}/users", [ 'role' => 'Bad Role' ] )->get_status() );
	}
}
