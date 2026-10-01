<?php
namespace MultisiteRadar\Tests;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Base des tests REST : serveur espion neuf à chaque test, requêtes JSON.
 */
abstract class RestTestCase extends TestCase {

	protected WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	protected function request( string $method, string $route, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/multisite-radar/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		return $this->server->dispatch( $request );
	}

	protected function login_as_super_admin(): void {
		$user_id = self::factory()->user->create();
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );
	}
}
