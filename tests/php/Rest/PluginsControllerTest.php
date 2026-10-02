<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class PluginsControllerTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_cache_set(
			'plugins',
			[
				'' => [
					'alpha/alpha.php'         => [
						'Name'    => 'Alpha',
						'Version' => '1.0',
					],
					'my plugin/my.plugin.php' => [
						'Name'    => 'My plugin',
						'Version' => '0.1',
					],
					'hello.php'               => [
						'Name'    => 'Hello Dolly',
						'Version' => '1.7.2',
					],
					'gamma/gamma.php'         => [
						'Name'    => 'Gamma',
						'Version' => '3.0',
					],
				],
			],
			'plugins'
		);
		update_site_option( 'active_sitewide_plugins', [ 'gamma/gamma.php' => time() ] );
		$scanned = '2026-09-01 00:00:00';
		$this->make_record(
			951,
			[
				'name'       => 'Alpha site',
				'scanned_at' => $scanned,
			]
		);
		$this->make_record(
			952,
			[
				'name'       => 'Beta site',
				'scanned_at' => $scanned,
			]
		);
		$this->make_record(
			953,
			[
				'name'       => 'Elsewhere',
				'network_id' => 2,
			]
		);
		$this->make_record( 954, [ 'name' => 'Quiet site' ] );
		$extensions = $this->plugin()->extensions();
		$extensions->replace_for_site( 951, [ 'alpha/alpha.php', 'my plugin/my.plugin.php' ], '', '' );
		$extensions->replace_for_site( 952, [ 'alpha/alpha.php' ], '', '' );
		$extensions->replace_for_site( 953, [ 'alpha/alpha.php' ], '', '' );
	}

	public function test_lists_plugins_with_pagination_headers_and_validation(): void {
		$this->assertSame( 401, $this->request( 'GET', '/plugins' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/plugins' )->get_status() );

		$this->login_as_super_admin();
		$response = $this->request(
			'GET',
			'/plugins',
			[
				'per_page' => 2,
				'orderby'  => 'sites_count',
				'order'    => 'desc',
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '4', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( [ 'gamma/gamma', 'alpha/alpha' ], wp_list_pluck( $response->get_data(), 'id' ) );
		$this->assertSame( [ 'hello' ], wp_list_pluck( $this->request( 'GET', '/plugins', [ 'status' => 'unused' ] )->get_data(), 'id' ) );

		foreach ( [ [ 'status' => [ 'gone' ] ], [ 'orderby' => 'file' ], [ 'per_page' => 101 ], [ 'has_update' => 'maybe' ] ] as $params ) {
			$this->assertSame( 400, $this->request( 'GET', '/plugins', $params )->get_status() );
		}
	}

	public function test_lists_the_sites_of_a_plugin_by_its_public_id(): void {
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/plugins/alpha/alpha/sites' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 951, 952 ], wp_list_pluck( $response->get_data(), 'id' ), 'Sorted by name, this network only.' );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );

		$this->assertSame( [ 951 ], wp_list_pluck( $this->request( 'GET', '/plugins/my plugin/my.plugin/sites' )->get_data(), 'id' ) );
		$this->assertSame( [], $this->request( 'GET', '/plugins/hello/sites' )->get_data(), 'Installed but active nowhere.' );
		$this->assertSame( 404, $this->request( 'GET', '/plugins/nope/nope/sites' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/plugins/alpha/alpha.php/sites' )->get_status(), 'The id has no .php.' );

		$network = $this->request( 'GET', '/plugins/gamma/gamma/sites', [ 'per_page' => 1 ] );
		$this->assertSame( (string) $this->plugin()->sites()->count_all( get_current_network_id() ), $network->get_headers()['X-WP-Total'], 'Network activated: every site of the network.' );
	}

	public function test_a_failed_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'GROUP BY e.slug' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$list  = $this->request( 'GET', '/plugins' );
			$sites = $this->request( 'GET', '/plugins/alpha/alpha/sites' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $list->get_status() );
		$this->assertSame( 500, $sites->get_status() );
	}
}
