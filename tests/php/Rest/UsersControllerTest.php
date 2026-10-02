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
		$this->assertArrayNotHasKey( 'email', $response->get_data()[0] );
		$this->assertStringNotContainsString( '@', (string) wp_json_encode( $response->get_data() ) );

		foreach ( [ [ 'membership' => 'many' ], [ 'orderby' => 'email' ], [ 'per_page' => 101 ], [ 'super_admin' => 'maybe' ] ] as $params ) {
			$this->assertSame( 400, $this->request( 'GET', '/users', $params )->get_status() );
		}
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
			$response = $this->request( 'GET', '/users', [ 'search' => 'nobody-matches-this' ] );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}
}
