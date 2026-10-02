<?php
namespace MultisiteRadar\Tests\Alerts;

use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Tests\TestCase;

final class NetworkStateTest extends TestCase {

	private static function plugin_update( string $file, string $version, int $checked = 1 ): object {
		return (object) [
			'last_checked' => $checked,
			'response'     => [ $file => (object) [ 'new_version' => $version ] ],
		];
	}

	public function test_reads_the_installed_themes_and_the_pending_updates(): void {
		set_site_transient( 'update_plugins', self::plugin_update( 'akismet/akismet.php', '9.0' ) );
		set_site_transient( 'update_themes', (object) [ 'response' => [ 'twentytwentyfive' => [ 'new_version' => '9.0' ] ] ] );
		$state = new NetworkState();

		$this->assertArrayHasKey( 'twentytwentyfive', $state->installed_themes() );
		$this->assertSame( [ 'akismet/akismet.php' => '9.0' ], $state->plugin_updates() );
		$this->assertSame( [ 'twentytwentyfive' => '9.0' ], $state->theme_updates() );
	}

	public function test_values_are_read_once_per_pass_until_reset(): void {
		delete_site_transient( 'update_plugins' );
		$state = new NetworkState();
		$this->assertSame( [], $state->plugin_updates() );

		set_site_transient( 'update_plugins', self::plugin_update( 'akismet/akismet.php', '9.0' ) );
		$this->assertSame( [], $state->plugin_updates(), 'Read once per pass.' );

		$state->reset();
		$this->assertSame( [ 'akismet/akismet.php' => '9.0' ], $state->plugin_updates() );
	}

	public function test_the_network_uses_https_when_its_main_site_does(): void {
		$state = new NetworkState();
		update_blog_option( get_main_site_id(), 'home', 'http://example.org' );
		$this->assertFalse( $state->uses_https() );

		update_blog_option( get_main_site_id(), 'home', 'https://example.org' );
		$state->reset();
		$this->assertTrue( $state->uses_https() );
	}

	public function test_upload_quotas_follow_the_network_settings(): void {
		$state = new NetworkState();

		update_site_option( 'upload_space_check_disabled', 1 );
		$this->assertFalse( $state->quotas_enabled() );
		update_site_option( 'upload_space_check_disabled', 0 );
		$this->assertTrue( $state->quotas_enabled() );

		update_site_option( 'blog_upload_space', 250 );
		$this->assertSame( 250, $state->default_quota_mb() );
		delete_site_option( 'blog_upload_space' );
		$this->assertSame( 100, $state->default_quota_mb(), 'The default of WordPress.' );
	}

	public function test_the_signature_ignores_the_date_of_the_update_check(): void {
		set_site_transient( 'update_plugins', self::plugin_update( 'a/a.php', '2.0', 1 ) );
		$state = new NetworkState();
		$first = $state->signature();

		set_site_transient( 'update_plugins', self::plugin_update( 'a/a.php', '2.0', 2 ) );
		$state->reset();
		$this->assertSame( $first, $state->signature() );

		set_site_transient( 'update_plugins', self::plugin_update( 'a/a.php', '2.1', 3 ) );
		$state->reset();
		$this->assertNotSame( $first, $state->signature() );
	}
}
