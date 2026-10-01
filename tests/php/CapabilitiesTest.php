<?php
namespace MultisiteRadar\Tests;

use MultisiteRadar\Capabilities;

final class CapabilitiesTest extends TestCase {

	public function test_super_admin_can_view_and_manage(): void {
		$user_id = self::factory()->user->create();
		grant_super_admin( $user_id );

		$this->assertTrue( user_can( $user_id, Capabilities::VIEW ) );
		$this->assertTrue( user_can( $user_id, Capabilities::MANAGE ) );
	}

	public function test_site_administrator_cannot_view_or_manage(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->assertFalse( user_can( $user_id, Capabilities::VIEW ) );
		$this->assertFalse( user_can( $user_id, Capabilities::MANAGE ) );
	}

	public function test_capability_map_is_filterable(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		add_filter(
			'msradar_capability_map',
			static function ( array $map ): array {
				$map[ Capabilities::VIEW ] = 'manage_options';
				return $map;
			}
		);

		$this->assertTrue( user_can( $user_id, Capabilities::VIEW ) );
		$this->assertFalse( user_can( $user_id, Capabilities::MANAGE ) );
	}
}
