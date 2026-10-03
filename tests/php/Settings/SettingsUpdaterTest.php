<?php
namespace MultisiteRadar\Tests\Settings;

use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Settings\SettingsUpdater;
use MultisiteRadar\Tests\TestCase;

final class SettingsUpdaterTest extends TestCase {

	private function updater(): SettingsUpdater {
		return new SettingsUpdater( $this->plugin()->settings(), $this->plugin()->rules() );
	}

	public function test_a_valid_change_is_saved_and_the_full_settings_are_returned(): void {
		$result = $this->updater()->apply( [ 'scan' => [ 'full_rescan_days' => 14 ] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 14, $result['scan']['full_rescan_days'] );
		$this->assertSame( [ 'post', 'page' ], $result['scan']['activity_post_types'], 'The other settings are kept.' );
		$this->assertSame( 14, $this->plugin()->settings()->get( 'scan.full_rescan_days' ) );
	}

	public function test_an_unknown_rule_is_refused_and_nothing_is_saved(): void {
		$before = get_site_option( Settings::OPTION, [] );

		$result = $this->updater()->apply(
			[
				'scan'   => [ 'full_rescan_days' => 14 ],
				'alerts' => [ 'rules' => [ 'acme_missing' => [ 'enabled' => false ] ] ],
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'msradar_unknown_rule', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( $before, get_site_option( Settings::OPTION, [] ) );
	}

	public function test_rule_parameters_must_follow_the_schema_of_the_rule(): void {
		$result = $this->updater()->apply( [ 'alerts' => [ 'rules' => [ 'inactive' => [ 'params' => [ 'months' => 0 ] ] ] ] ] );

		$this->assertWPError( $result );
		$this->assertSame( 'msradar_invalid_settings', $result->get_error_code() );
	}

	public function test_a_value_outside_the_settings_schema_is_refused(): void {
		$this->assertWPError( $this->updater()->apply( [ 'scan' => [ 'full_rescan_days' => 0 ] ] ) );
		$this->assertWPError( $this->updater()->apply( [ 'scan' => [ 'unknown_key' => 1 ] ] ) );
		$this->assertSame( 7, $this->plugin()->settings()->get( 'scan.full_rescan_days' ) );
	}

	public function test_a_dotted_path_becomes_a_nested_change(): void {
		$this->assertSame( [ 'scan' => [ 'full_rescan_days' => 14 ] ], SettingsUpdater::patch_for( 'scan.full_rescan_days', 14 ) );
		$this->assertSame( [ 'integrations' => [ 'mcp_public' => true ] ], SettingsUpdater::patch_for( 'integrations.mcp_public', true ) );
		$this->assertSame(
			[ 'alerts' => [ 'rules' => [ 'inactive' => [ 'enabled' => false ] ] ] ],
			SettingsUpdater::patch_for( 'alerts.rules.inactive', [ 'enabled' => false ] )
		);
		$this->assertNull( SettingsUpdater::patch_for( '', 1 ) );
		$this->assertNull( SettingsUpdater::patch_for( 'scan..full_rescan_days', 1 ) );
		$this->assertNull( SettingsUpdater::patch_for( 'scan.', 1 ) );
	}
}
