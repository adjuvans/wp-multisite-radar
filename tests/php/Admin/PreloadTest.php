<?php
namespace MultisiteRadar\Tests\Admin;

use MultisiteRadar\Admin\Preload;
use MultisiteRadar\Settings\Preferences;
use MultisiteRadar\Tests\RestTestCase;

final class PreloadTest extends RestTestCase {

	public function test_paths_per_view(): void {
		$prefs = Preferences::defaults();

		$this->assertSame( [ '/multisite-radar/v1/preferences', '/multisite-radar/v1/alerts/summary', '/multisite-radar/v1/scan/status' ], Preload::paths( 'overview', [], $prefs ) );
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/alerts/summary', '/multisite-radar/v1/sites?order=asc&orderby=name&page=1&per_page=20', '/multisite-radar/v1/sites/12' ],
			Preload::paths( 'sites', [ 'site' => '12' ], $prefs )
		);
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/alerts/summary', '/multisite-radar/v1/alerts?order=asc&orderby=rule&page=1&per_page=20' ],
			Preload::paths( 'alerts', [], $prefs )
		);
		$this->assertSame( [ '/multisite-radar/v1/preferences', '/multisite-radar/v1/settings' ], Preload::paths( 'settings', [], $prefs ) );
	}

	public function test_run_preloads_successful_responses_only(): void {
		$this->login_as_super_admin();
		$this->make_record(
			101,
			[
				'name'       => 'Alpha',
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);

		$data = Preload::run( Preload::paths( 'sites', [ 'site' => '999999' ], Preferences::defaults() ) );

		$list = $data['/multisite-radar/v1/sites?order=asc&orderby=name&page=1&per_page=20'];
		$this->assertContains( 'Alpha', wp_list_pluck( $list['body'], 'name' ) );
		$this->assertArrayHasKey( 'X-WP-Total', $list['headers'] );
		$this->assertArrayNotHasKey( '/multisite-radar/v1/sites/999999', $data, 'A 404 is not preloaded: the client shows its own error.' );
	}
}
