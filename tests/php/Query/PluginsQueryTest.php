<?php
namespace MultisiteRadar\Tests\Query;

use MultisiteRadar\Query\PluginsQuery;
use MultisiteRadar\Tests\TestCase;

final class PluginsQueryTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// get_plugins() lit d'abord ce cache : le test connaît ainsi les plugins « installés ».
		wp_cache_set(
			'plugins',
			[
				'' => [
					'alpha/alpha.php' => [
						'Name'    => 'Alpha',
						'Version' => '1.0',
					],
					'beta/beta.php'   => [
						'Name'    => 'Beta &amp; <em>Co</em>',
						'Version' => '2.1',
					],
					'gamma/gamma.php' => [
						'Name'    => 'Gamma',
						'Version' => '3.0',
					],
					'hello.php'       => [
						'Name'    => 'Hello Dolly',
						'Version' => '1.7.2',
					],
				],
			],
			'plugins'
		);
		update_site_option( 'active_sitewide_plugins', [ 'gamma/gamma.php' => time() ] );
		set_site_transient(
			'update_plugins',
			(object) [
				'response' => [
					'alpha/alpha.php' => (object) [ 'new_version' => '1.1' ],
					'ghost/ghost.php' => (object) [ 'new_version' => '9.0' ],
				],
			]
		);
		$this->make_record( 941 );
		$this->make_record( 942 );
		$this->make_record( 943, [ 'network_id' => 2 ] );
		$this->make_record( 944 );
		$extensions = $this->plugin()->extensions();
		$extensions->replace_for_site( 941, [ 'alpha/alpha.php', 'ghost/ghost.php', 'gamma/gamma.php' ], '', '' );
		$extensions->replace_for_site( 942, [ 'alpha/alpha.php' ], '', '' );
		$extensions->replace_for_site( 943, [ 'beta/beta.php' ], '', '' );
	}

	private function query(): PluginsQuery {
		return $this->plugin()->plugins_query();
	}

	/**
	 * @param array[] $items
	 */
	private static function ids( array $items ): array {
		return array_values( wp_list_pluck( $items, 'id' ) );
	}

	public function test_every_plugin_with_its_status_and_number_of_sites(): void {
		$total   = $this->plugin()->sites()->count_all( get_current_network_id() );
		$summary = [];
		foreach ( $this->query()->all() as $item ) {
			$summary[ $item['id'] ] = [ $item['status'], $item['sites_count'], $item['update_version'] ];
		}

		$this->assertSame(
			[
				'alpha/alpha' => [ 'local', 2, '1.1' ],
				'beta/beta'   => [ 'unused', 0, null ],
				'gamma/gamma' => [ 'network', $total, null ],
				'ghost/ghost' => [ 'missing', 1, null ],
				'hello'       => [ 'unused', 0, null ],
			],
			$summary,
			'Network activated wins over local activations; another network is not counted.'
		);
		$ghost = $this->query()->find( 'ghost/ghost' );
		$this->assertSame( [ 'id', 'file', 'name', 'version', 'installed', 'network_active', 'sites_count', 'status', 'update_version' ], array_keys( $ghost ) );
		$this->assertFalse( $ghost['installed'] );
		$this->assertSame( 'ghost/ghost.php', $ghost['name'] );
		$this->assertSame( 'Beta & Co', $this->query()->find( 'beta/beta' )['name'] );
		$this->assertTrue( $this->query()->find( 'gamma/gamma' )['network_active'] );
	}

	public function test_search_status_updates_sort_and_pages(): void {
		$this->assertSame( [ 'beta/beta' ], self::ids( $this->query()->list( [ 'search' => 'co' ] )['items'] ) );
		$this->assertSame( [ 'hello' ], self::ids( $this->query()->list( [ 'search' => 'HELLO.PHP' ] )['items'] ) );
		$this->assertSame( [ 'beta/beta', 'hello' ], self::ids( $this->query()->list( [ 'status' => [ 'unused', 'bogus' ] ] )['items'] ) );
		$this->assertSame( [ 'alpha/alpha' ], self::ids( $this->query()->list( [ 'has_update' => true ] )['items'] ) );
		$this->assertSame(
			[ 'gamma/gamma', 'alpha/alpha', 'ghost/ghost', 'beta/beta', 'hello' ],
			self::ids(
				$this->query()->list(
					[
						'orderby' => 'sites_count',
						'order'   => 'desc',
					]
				)['items']
			),
			'Equal counts keep the name order.'
		);
		$this->assertSame( [ 'hello', 'ghost/ghost', 'gamma/gamma', 'beta/beta', 'alpha/alpha' ], self::ids( $this->query()->list( [ 'order' => 'desc' ] )['items'] ) );

		$page = $this->query()->list(
			[
				'per_page' => 2,
				'page'     => 2,
			]
		);
		$this->assertSame( [ 'gamma/gamma', 'ghost/ghost' ], self::ids( $page['items'] ) );
		$this->assertSame( 5, $page['total'] );
		$this->assertSame( [], $this->query()->list( [ 'page' => 99 ] )['items'] );
		$this->assertCount( 5, $this->query()->filtered( [] ), 'filtered() has no page limit.' );
	}

	public function test_find_uses_the_public_id(): void {
		$this->assertSame( 'hello.php', $this->query()->find( 'hello' )['file'] );
		$this->assertSame( 'alpha/alpha.php', $this->query()->find( 'alpha/alpha' )['file'] );
		$this->assertNull( $this->query()->find( 'alpha/alpha.php' ) );
		$this->assertNull( $this->query()->find( 'nope' ) );
		$this->assertSame( 'my plugin/my.plugin', PluginsQuery::id( 'my plugin/my.plugin.php' ) );
	}

	public function test_summary(): void {
		$this->assertSame(
			[
				'installed' => 4,
				'network'   => 1,
				'unused'    => 2,
				'missing'   => 1,
				'updates'   => 1,
			],
			$this->query()->summary()
		);
	}
}
