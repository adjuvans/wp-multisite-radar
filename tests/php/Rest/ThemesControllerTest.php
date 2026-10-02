<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class ThemesControllerTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->make_record(
			971,
			[
				'name'             => 'Child site',
				'theme_stylesheet' => 'msradar-child',
				'theme_template'   => 'msradar-parent',
			]
		);
		$this->make_record(
			972,
			[
				'name'             => 'Parent site',
				'theme_stylesheet' => 'msradar-parent',
				'theme_template'   => 'msradar-parent',
			]
		);
		$this->make_record(
			973,
			[
				'name'             => 'Elsewhere',
				'network_id'       => 2,
				'theme_stylesheet' => 'msradar-parent',
				'theme_template'   => 'msradar-parent',
			]
		);
	}

	public function test_lists_themes_with_validation(): void {
		$this->assertSame( 401, $this->request( 'GET', '/themes' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/themes' )->get_status() );

		$this->login_as_super_admin();
		$response = $this->request( 'GET', '/themes', [ 'status' => 'missing' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'msradar-child', 'msradar-parent' ], wp_list_pluck( $response->get_data(), 'id' ) );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );

		foreach ( [ [ 'status' => [ 'gone' ] ], [ 'orderby' => 'stylesheet' ], [ 'per_page' => 0 ] ] as $params ) {
			$this->assertSame( 400, $this->request( 'GET', '/themes', $params )->get_status() );
		}
	}

	public function test_lists_the_sites_of_a_theme_active_or_as_parent(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/themes/msradar-parent/sites' )->get_status() );
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/themes/msradar-parent/sites' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 971, 972 ], wp_list_pluck( $response->get_data(), 'id' ) );
		$this->assertSame( [ 971 ], wp_list_pluck( $this->request( 'GET', '/themes/msradar-child/sites' )->get_data(), 'id' ) );
		$this->assertSame( 404, $this->request( 'GET', '/themes/nope/sites' )->get_status() );
	}

	public function test_a_failed_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'GROUP BY theme_stylesheet' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/themes' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}
}
