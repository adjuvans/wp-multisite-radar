<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Tests\TestCase;

final class FindExtensionUsageAbilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );

		wp_cache_set(
			'plugins',
			[
				'' => [
					'alpha/alpha.php' => [
						'Name'    => 'Alpha',
						'Version' => '1.0',
					],
					'gamma/gamma.php' => [
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
			4101,
			[
				'name'             => 'Usage one',
				'scanned_at'       => $scanned,
				'theme_stylesheet' => 'msradar-child',
				'theme_template'   => 'msradar-parent',
			]
		);
		$this->make_record(
			4102,
			[
				'name'             => 'Usage two',
				'scanned_at'       => $scanned,
				'theme_stylesheet' => 'msradar-parent',
				'theme_template'   => 'msradar-parent',
			]
		);
		$this->plugin()->extensions()->replace_for_site( 4101, [ 'alpha/alpha.php' ], '', '' );
		$this->plugin()->extensions()->replace_for_site( 4102, [ 'alpha/alpha.php' ], '', '' );
	}

	/**
	 * @param mixed $input Entrée de l'ability.
	 * @return array|\WP_Error
	 */
	private function find( $input ) {
		$ability = wp_get_ability( 'multisite-radar/find-extension-usage' );
		$this->assertNotNull( $ability );
		return $ability->execute( $input );
	}

	public function test_finds_the_sites_of_a_plugin_by_its_file_with_or_without_php(): void {
		foreach ( [ 'alpha/alpha.php', 'alpha/alpha' ] as $id ) {
			$result = $this->find(
				[
					'type' => 'plugin',
					'id'   => $id,
				]
			);

			$this->assertIsArray( $result, $id );
			$this->assertSame( 'alpha/alpha.php', $result['extension']['file'] );
			$this->assertSame( 'local', $result['extension']['status'] );
			$this->assertSame( [ 4101, 4102 ], array_column( $result['sites']['items'], 'id' ), 'Sorted by name.' );
			$this->assertSame( 2, $result['sites']['total'] );
		}
	}

	public function test_a_network_activated_plugin_is_used_by_every_site(): void {
		$result = $this->find(
			[
				'type'     => 'plugin',
				'id'       => 'gamma/gamma.php',
				'per_page' => 1,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'network', $result['extension']['status'] );
		$this->assertSame( $this->plugin()->sites()->count_all( get_current_network_id() ), $result['sites']['total'] );
		$this->assertCount( 1, $result['sites']['items'] );
	}

	public function test_finds_the_sites_of_a_theme_used_as_active_or_parent_theme(): void {
		$result = $this->find(
			[
				'type' => 'theme',
				'id'   => 'msradar-parent',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'msradar-parent', $result['extension']['stylesheet'] );
		$this->assertSame( [ 4101, 4102 ], array_column( $result['sites']['items'], 'id' ) );
	}

	public function test_an_unknown_extension_is_not_found(): void {
		$result = $this->find(
			[
				'type' => 'plugin',
				'id'   => 'nope/nope.php',
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'msradar_extension_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_the_kind_and_the_identifier_are_required(): void {
		$inputs = [
			[ 'type' => 'plugin' ],
			[ 'id' => 'alpha/alpha.php' ],
			[
				'type' => 'widget',
				'id'   => 'alpha/alpha.php',
			],
			[
				'type' => 'plugin',
				'id'   => '',
			],
		];
		foreach ( $inputs as $input ) {
			$this->assertSame( 'ability_invalid_input', $this->find( $input )->get_error_code(), (string) wp_json_encode( $input ) );
		}
	}
}
