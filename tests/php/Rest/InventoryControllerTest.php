<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class InventoryControllerTest extends RestTestCase {

	public function test_summarises_plugins_themes_and_sites_still_to_analyse(): void {
		$this->assertSame( 401, $this->request( 'GET', '/inventory/summary' )->get_status() );

		$this->login_as_super_admin();
		wp_cache_set(
			'plugins',
			[
				'' => [
					'alpha/alpha.php' => [
						'Name'    => 'Alpha',
						'Version' => '1.0',
					],
				],
			],
			'plugins'
		);
		$this->make_record( 991, [ 'scanned_at' => '2026-09-01 00:00:00' ] );
		$this->make_record( 992 );
		$pending = $this->plugin()->sites()->count_pending( get_current_network_id() );

		$response = $this->request( 'GET', '/inventory/summary' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( [ 'pending_sites', 'plugins', 'themes' ], array_keys( $data ) );
		$this->assertSame( $pending, $data['pending_sites'] );
		$this->assertGreaterThanOrEqual( 1, $data['pending_sites'] );
		$this->assertSame(
			[
				'installed' => 1,
				'network'   => 0,
				'unused'    => 1,
				'missing'   => 0,
				'updates'   => 0,
			],
			$data['plugins']
		);
		$this->assertSame( [ 'installed', 'unused', 'missing', 'updates' ], array_keys( $data['themes'] ) );
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
			$response = $this->request( 'GET', '/inventory/summary' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}
}
