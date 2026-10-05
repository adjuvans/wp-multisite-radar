<?php
namespace MultisiteRadar\Tests\Abilities;

use MultisiteRadar\Tests\TestCase;

final class RecentChangesAbilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
		$this->make_record(
			5001,
			[
				'name'       => 'Changing site',
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);
		$event = static function ( string $type, string $subject, string $created_at ): array {
			return [
				'network_id' => get_current_network_id(),
				'site_id'    => 5001,
				'type'       => $type,
				'subject'    => $subject,
				'meta'       => [],
				'created_at' => $created_at,
			];
		};
		$this->plugin()->events()->insert(
			[
				$event( 'site_created', 'example.org/changing/', '2026-08-01 00:00:00' ),
				$event( 'alert_raised', 'no_users', '2026-09-10 00:00:00' ),
			]
		);
	}

	/**
	 * @param mixed $input Entrée de l'ability.
	 * @return array|\WP_Error
	 */
	private function changes( $input ) {
		$ability = wp_get_ability( 'multisite-radar/recent-changes' );
		$this->assertNotNull( $ability );
		return $ability->execute( $input );
	}

	public function test_lists_the_changes_of_a_site_newest_first(): void {
		$result = $this->changes( [ 'site' => 5001 ] );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( [ 'alert_raised', 'site_created' ], array_column( $result['items'], 'type' ) );
		$this->assertSame( 'Changing site', $result['items'][0]['site']['name'] );
	}

	public function test_filters_by_date_and_kind_sent_as_text(): void {
		$this->assertSame( 1, $this->changes( [ 'site' => '5001', 'since' => '2026-09-01T00:00:00' ] )['total'] );
		$this->assertSame( [ 'site_created' ], array_column( $this->changes( [ 'site' => 5001, 'type' => 'site_created' ] )['items'], 'type' ) );
	}

	public function test_invalid_input_and_missing_capability_are_refused(): void {
		$this->assertSame( 'ability_invalid_input', $this->changes( [ 'type' => [ 'gone' ] ] )->get_error_code() );
		$this->assertSame( 'ability_invalid_input', $this->changes( [ 'since' => 'yesterday' ] )->get_error_code() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'ability_invalid_permissions', $this->changes( [] )->get_error_code() );
	}
}
