<?php
namespace MultisiteRadar\Tests\Rest;

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
}
