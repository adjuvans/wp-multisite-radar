<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class UsersControllerTest extends RestTestCase {

	public function test_lists_accounts_with_pagination_headers_and_validation(): void {
		$this->assertSame( 401, $this->request( 'GET', '/users' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/users' )->get_status() );

		$this->login_as_super_admin();
		self::factory()->user->create( [ 'user_login' => 'radar_rest_a' ] );
		self::factory()->user->create( [ 'user_login' => 'radar_rest_b' ] );

		$response = $this->request(
			'GET',
			'/users',
			[
				'search'   => 'radar_rest_',
				'per_page' => 1,
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( 'radar_rest_a', $response->get_data()[0]['login'] );
		$this->assertArrayHasKey( 'email', $response->get_data()[0] );

		foreach ( [ [ 'membership' => 'many' ], [ 'orderby' => 'bogus' ], [ 'per_page' => 101 ], [ 'super_admin' => 'maybe' ] ] as $params ) {
			$this->assertSame( 400, $this->request( 'GET', '/users', $params )->get_status() );
		}
	}

	public function test_only_accounts_that_can_manage_users_see_the_emails(): void {
		self::factory()->user->create(
			[
				'user_login' => 'radar_rest_mail',
				'user_email' => 'mail@radar.test',
			]
		);
		$map = static fn ( array $caps ): array => array_merge( $caps, [ \MultisiteRadar\Capabilities::VIEW => 'manage_options' ] );
		add_filter( 'msradar_capability_map', $map );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		try {
			$list = $this->request( 'GET', '/users', [ 'search' => 'radar_rest_mail' ] );
			$this->assertSame( 200, $list->get_status() );
			$this->assertArrayNotHasKey( 'email', $list->get_data()[0] );
			$this->assertSame( [], $this->request( 'GET', '/users', [ 'search' => 'mail@radar' ] )->get_data() );
			$this->assertStringNotContainsString( 'mail@radar.test', (string) wp_json_encode( $this->request( 'GET', '/users/' . $list->get_data()[0]['id'] )->get_data() ) );
		} finally {
			remove_filter( 'msradar_capability_map', $map );
		}
	}

	public function test_the_detail_route(): void {
		$this->assertSame( 401, $this->request( 'GET', '/users/1' )->get_status() );
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/users/1' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['id'] );
		$this->assertArrayHasKey( 'email', $response->get_data() );

		$missing = $this->request( 'GET', '/users/999999' );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'msradar_user_not_found', $missing->get_data()['code'] );
	}

	public function test_a_failed_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'AS sites_count' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/users', [ 'search' => 'admin' ] );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}
}
