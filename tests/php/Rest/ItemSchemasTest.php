<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;
use WP_REST_Request;

/**
 * Chaque route de lecture publie le schéma de ce qu'elle renvoie : chaque clé d'un élément y est décrite, et le schéma
 * ne décrit rien qui ne soit renvoyé (les abilities valident leur sortie avec ces mêmes schémas).
 */
final class ItemSchemasTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->login_as_super_admin();
		$this->make_record(
			961,
			[
				'name'        => 'Schema site',
				'url'         => 'example.org/schema/',
				'scanned_at'  => '2026-09-01 00:00:00',
				'alert_level' => 3,
				'alert_rules' => ',no_users,',
			]
		);
		$this->plugin()->events()->insert(
			[
				[
					'network_id' => get_current_network_id(),
					'site_id'    => 961,
					'type'       => 'alert_raised',
					'subject'    => 'no_users',
					'meta'       => [ 'severity' => 'error' ],
					'created_at' => '2026-09-01 00:00:00',
				],
			]
		);
	}

	/**
	 * @return array<string, array{0: string, 1: bool}> Route, et vrai si elle renvoie une liste.
	 */
	public function routes(): array {
		return [
			'sites'             => [ '/sites', true ],
			'plugins'           => [ '/plugins', true ],
			'themes'            => [ '/themes', true ],
			'users'             => [ '/users', true ],
			'alerts'            => [ '/alerts', true ],
			'events'            => [ '/events', true ],
			'alerts summary'    => [ '/alerts/summary', false ],
			'inventory summary' => [ '/inventory/summary', false ],
			'scan status'       => [ '/scan/status', false ],
			'trends'            => [ '/reports/trends', false ],
		];
	}

	/**
	 * @dataProvider routes
	 */
	public function test_the_schema_of_a_route_describes_exactly_what_it_returns( string $route, bool $is_list ): void {
		$response = $this->request( 'GET', $route );
		$this->assertSame( 200, $response->get_status(), $route );
		$data = $response->get_data();
		$item = $is_list ? ( $data[0] ?? null ) : $data;
		$this->assertIsArray( $item, "$route returned nothing to compare." );

		$options = $this->server->dispatch( new WP_REST_Request( 'OPTIONS', '/multisite-radar/v1' . $route ) )->get_data();
		$this->assertArrayHasKey( 'schema', $options, "$route publishes no schema." );
		$this->assertSame( 'object', $options['schema']['type'] );
		$this->assertEqualsCanonicalizing( array_keys( $item ), array_keys( $options['schema']['properties'] ), $route );
	}

	/**
	 * Les routes qui demandent des données en plus (membre d'un site, extension utilisée) : même comparaison.
	 */
	public function test_the_schema_of_the_detail_and_sub_routes_describes_what_they_return(): void {
		$site_id = self::factory()->blog->create();
		add_user_to_blog( $site_id, 1, 'administrator' );
		$this->make_record( 963, [ 'name' => 'Uses things', 'theme_stylesheet' => 'msradar-parent', 'theme_template' => 'msradar-parent' ] );
		wp_cache_set(
			'plugins',
			[ '' => [ 'alpha/alpha.php' => [ 'Name' => 'Alpha', 'Version' => '1.0' ] ] ],
			'plugins'
		);
		$this->plugin()->extensions()->replace_for_site( 963, [ 'alpha/alpha.php' ], '', '' );

		$cases = [
			'/sites/961'                 => false,
			"/sites/{$site_id}/users"    => true,
			'/plugins/alpha/alpha/sites' => true,
			'/themes/msradar-parent/sites' => true,
		];
		foreach ( $cases as $route => $is_list ) {
			$response = $this->request( 'GET', $route );
			$this->assertSame( 200, $response->get_status(), $route );
			$data = $response->get_data();
			$item = $is_list ? ( $data[0] ?? null ) : $data;
			$this->assertIsArray( $item, "$route returned nothing to compare." );

			$options = $this->server->dispatch( new WP_REST_Request( 'OPTIONS', '/multisite-radar/v1' . $route ) )->get_data();
			$this->assertArrayHasKey( 'schema', $options, "$route publishes no schema." );
			$this->assertEqualsCanonicalizing( array_keys( $item ), array_keys( $options['schema']['properties'] ), $route );
		}
	}

	public function test_the_site_schema_accepts_a_site_that_was_never_analysed(): void {
		$this->make_record( 962, [ 'name' => 'Never analysed' ] );
		$site = $this->plugin()->sites_query()->get( 962 );

		$this->assertNotNull( $site );
		$this->assertNull( $site['disk_bytes'] );
		$this->assertNull( $site['scanned_at_gmt'] );
		$this->assertTrue( rest_validate_value_from_schema( $site, \MultisiteRadar\Query\Schemas::site_detail(), 'site' ) );
	}
}
