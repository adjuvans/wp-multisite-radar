<?php
namespace MultisiteRadar\Tests\Settings;

use MultisiteRadar\Settings\Preferences;
use MultisiteRadar\Tests\TestCase;

final class PreferencesTest extends TestCase {

	public function test_defaults_until_something_is_saved(): void {
		$user = self::factory()->user->create();

		$this->assertSame( Preferences::defaults(), $this->plugin()->preferences()->get( $user ) );
	}

	public function test_update_merges_per_view_and_replaces_lists(): void {
		$user  = self::factory()->user->create();
		$prefs = $this->plugin()->preferences();

		$prefs->update( $user, [ 'sites' => [ 'fields' => [ 'theme', 'users_count' ], 'per_page' => 50 ] ] );
		$result = $prefs->update( $user, [ 'sites' => [ 'fields' => [ 'media_count' ] ] ] );

		$this->assertSame(
			[
				'fields'   => [ 'media_count' ],
				'layout'   => 'table',
				'per_page' => 50,
			],
			$result['sites']
		);
		$this->assertSame( Preferences::defaults()['alerts'], $result['alerts'] );
		$this->assertSame( $result, $prefs->get( $user ) );
	}

	public function test_invalid_patches_are_rejected(): void {
		$user = self::factory()->user->create();

		foreach ( [
			[ 'sites' => [ 'per_page' => 7 ] ],
			[ 'sites' => [ 'layout' => 'list' ] ],
			[ 'sites' => [ 'fields' => [ 'Bad Field' ] ] ],
			[ 'alerts' => [ 'layout' => 'grid' ] ],
			[ 'users' => [ 'layout' => 'grid' ] ],
			[ 'plugins' => [ 'fields' => [ "name\n" ] ] ],
			[ 'other' => [] ],
		] as $patch ) {
			$result = $this->plugin()->preferences()->update( $user, $patch );
			$this->assertWPError( $result );
			$this->assertSame( 'msradar_invalid_preferences', $result->get_error_code() );
		}
		$this->assertSame( Preferences::defaults(), $this->plugin()->preferences()->get( $user ) );
	}

	public function test_the_inventory_views_have_their_own_preferences(): void {
		$user   = self::factory()->user->create();
		$result = $this->plugin()->preferences()->update(
			$user,
			[
				'plugins' => [ 'per_page' => 50 ],
				'users'   => [ 'fields' => [ 'sites_count' ] ],
			]
		);

		$this->assertSame(
			[
				'fields'   => [],
				'per_page' => 50,
			],
			$result['plugins']
		);
		$this->assertSame(
			[
				'fields'   => [ 'sites_count' ],
				'per_page' => 20,
			],
			$result['users']
		);
		$this->assertSame( Preferences::defaults()['themes'], $result['themes'] );
	}

	public function test_a_corrupted_view_falls_back_to_its_defaults_without_losing_the_others(): void {
		$user = self::factory()->user->create();
		update_user_meta(
			$user,
			Preferences::META,
			[
				'sites'  => [ 'per_page' => 'lots' ],
				'alerts' => [ 'per_page' => 100 ],
			]
		);

		$prefs = $this->plugin()->preferences()->get( $user );

		$this->assertSame( Preferences::defaults()['sites'], $prefs['sites'] );
		$this->assertSame( 100, $prefs['alerts']['per_page'] );
	}

	public function test_the_upgrade_to_version_5_adds_the_new_users_columns_once(): void {
		$chose = self::factory()->user->create();
		$kept  = self::factory()->user->create();
		$prefs = $this->plugin()->preferences();
		$prefs->update( $chose, [ 'users' => [ 'fields' => [ 'sites_count', 'registered_gmt' ] ] ] );
		$prefs->update( $kept, [ 'sites' => [ 'fields' => [ 'theme' ] ] ] );

		$prefs->on_upgraded( 5, 4 );

		$this->assertSame( [ 'display_name', 'email', 'sites_count', 'registered_gmt' ], $prefs->get( $chose )['users']['fields'] );
		$this->assertSame( [], $prefs->get( $kept )['users']['fields'] );

		$prefs->update( $chose, [ 'users' => [ 'fields' => [ 'sites_count' ] ] ] );
		$prefs->on_upgraded( 5, 5 );
		$this->assertSame( [ 'sites_count' ], $prefs->get( $chose )['users']['fields'] );
	}
}
