<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class EventsControllerTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$events = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$events[] = [
				'network_id' => get_current_network_id(),
				'site_id'    => 4701,
				'type'       => 'alert_raised',
				'subject'    => 'no_users',
				'meta'       => [],
				'created_at' => '2026-09-1' . $i . ' 00:00:00',
			];
		}
		$this->plugin()->events()->insert( $events );
	}

	public function test_lists_events_with_pagination_headers_for_the_view_capability(): void {
		$this->assertSame( 401, $this->request( 'GET', '/events' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/events' )->get_status() );

		$this->login_as_super_admin();
		$response = $this->request(
			'GET',
			'/events',
			[
				'site'     => 4701,
				'per_page' => 2,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( [ '2026-09-13T00:00:00', '2026-09-12T00:00:00' ], array_column( $response->get_data(), 'created_gmt' ) );
		$this->assertCount( 1, $this->request( 'GET', '/events', [ 'site' => 4701, 'since' => '2026-09-13T00:00:00' ] )->get_data() );
	}

	public function test_invalid_parameters_are_refused(): void {
		$this->login_as_super_admin();
		foreach ( [ [ 'type' => [ 'gone' ] ], [ 'per_page' => 101 ], [ 'site' => 0 ], [ 'since' => 'yesterday' ] ] as $params ) {
			$this->assertSame( 400, $this->request( 'GET', '/events', $params )->get_status(), (string) wp_json_encode( $params ) );
		}
	}

	public function test_a_failed_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'msradar_events' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/events' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}
}
