<?php
namespace MultisiteRadar\Tests\Settings;

use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Tests\TestCase;

final class SettingsTest extends TestCase {

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( Settings::OPTION );
		$this->settings = new Settings();
	}

	public function test_defaults_apply_when_nothing_is_stored(): void {
		$this->assertSame( [ 'post', 'page' ], $this->settings->get( 'scan.activity_post_types' ) );
		$this->assertSame( 7, $this->settings->get( 'scan.full_rescan_days' ) );
		$this->assertFalse( $this->settings->get( 'integrations.mcp_public' ) );
		$this->assertFalse( $this->settings->get( 'sites_menu.enabled' ) );
		$this->assertSame( 'fallback', $this->settings->get( 'scan.unknown', 'fallback' ) );
	}

	public function test_update_merges_a_partial_patch_and_persists_it(): void {
		$result = $this->settings->update( [ 'scan' => [ 'full_rescan_days' => 3 ] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $this->settings->get( 'scan.full_rescan_days' ) );
		$this->assertSame( [ 'post', 'page' ], $this->settings->get( 'scan.activity_post_types' ), 'Untouched keys keep their value.' );
		$this->assertSame( 3, ( new Settings() )->get( 'scan.full_rescan_days' ), 'Value is persisted.' );
		$this->assertSame( [ 'scan' => [ 'full_rescan_days' => 3 ] ], get_site_option( Settings::OPTION ), 'Only overrides are stored.' );
	}

	public function test_lists_are_replaced_not_merged(): void {
		$this->settings->update( [ 'scan' => [ 'activity_post_types' => [ 'event' ] ] ] );

		$this->assertSame( [ 'event' ], $this->settings->get( 'scan.activity_post_types' ) );
	}

	public function test_rule_overrides_are_kept_per_rule(): void {
		$this->settings->update( [ 'alerts' => [ 'rules' => [ 'inactive' => [ 'enabled' => false ] ] ] ] );
		$this->settings->update( [ 'alerts' => [ 'rules' => [ 'high_media' => [ 'severity' => 'warning' ] ] ] ] );
		$this->settings->update( [ 'alerts' => [ 'rules' => [ 'no_users' => [ 'severity' => null ] ] ] ] );

		$this->assertSame( [ 'enabled' => false ], $this->settings->rule_config( 'inactive' ) );
		$this->assertSame( [ 'severity' => 'warning' ], $this->settings->rule_config( 'high_media' ) );
		$this->assertSame( [ 'severity' => null ], $this->settings->rule_config( 'no_users' ) );
		$this->assertSame( [], $this->settings->rule_config( 'unknown_rule' ) );
	}

	/**
	 * @dataProvider invalid_patches
	 */
	public function test_invalid_patch_is_rejected_and_nothing_is_saved( array $patch ): void {
		$result = $this->settings->update( $patch );

		$this->assertWPError( $result );
		$this->assertSame( 'msradar_invalid_settings', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertFalse( get_site_option( Settings::OPTION ) );
	}

	public static function invalid_patches(): array {
		return [
			'unknown section'      => [ [ 'nope' => true ] ],
			'days out of range'    => [ [ 'scan' => [ 'full_rescan_days' => 0 ] ] ],
			'empty activity types' => [ [ 'scan' => [ 'activity_post_types' => [] ] ] ],
			'invalid post type'    => [ [ 'scan' => [ 'activity_post_types' => [ 'Bad Type!' ] ] ] ],
			'invalid severity'     => [ [ 'alerts' => [ 'rules' => [ 'inactive' => [ 'severity' => 'fatal' ] ] ] ] ],
			'invalid email'        => [ [ 'reports' => [ 'digest_recipients' => [ 'emails' => [ 'not-an-email' ] ] ] ] ],
		];
	}

	public function test_update_fires_an_action_with_new_and_old_values(): void {
		$seen = [];
		add_action(
			'msradar_settings_updated',
			static function ( array $new_settings, array $old_settings ) use ( &$seen ): void {
				$seen = [ $new_settings['scan']['full_rescan_days'], $old_settings['scan']['full_rescan_days'] ];
			},
			10,
			2
		);

		$this->settings->update( [ 'scan' => [ 'full_rescan_days' => 30 ] ] );

		$this->assertSame( [ 30, 7 ], $seen );
	}
}
