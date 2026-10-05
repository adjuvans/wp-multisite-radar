<?php
namespace MultisiteRadar\Tests\Admin;

use MultisiteRadar\Admin\Preload;
use MultisiteRadar\Settings\Preferences;
use MultisiteRadar\Tests\RestTestCase;

final class PreloadTest extends RestTestCase {

	public function test_paths_per_view(): void {
		$prefs = Preferences::defaults();

		$this->assertSame( [ '/multisite-radar/v1/preferences', '/multisite-radar/v1/alerts/summary', '/multisite-radar/v1/inventory/summary', '/multisite-radar/v1/scan/status', '/multisite-radar/v1/events?page=1&per_page=5', '/multisite-radar/v1/reports/trends?days=30' ], Preload::paths( 'overview', [], $prefs ) );
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/alerts/summary', '/multisite-radar/v1/sites?order=asc&orderby=name&page=1&per_page=20', '/multisite-radar/v1/sites/12' ],
			Preload::paths( 'sites', [ 'site' => '12' ], $prefs )
		);
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/inventory/summary', '/multisite-radar/v1/plugins?has_update=1&order=asc&orderby=name&page=1&per_page=20' ],
			Preload::paths( 'plugins', [ 'has_update' => '1' ], $prefs )
		);
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/inventory/summary', '/multisite-radar/v1/themes?order=asc&orderby=name&page=1&per_page=20&status=unused' ],
			Preload::paths( 'themes', [ 'status' => 'unused' ], $prefs )
		);
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/users?membership=none&order=asc&orderby=login&page=1&per_page=20' ],
			Preload::paths( 'users', [ 'membership' => 'none' ], $prefs )
		);
		$this->assertSame(
			[ '/multisite-radar/v1/preferences', '/multisite-radar/v1/alerts/summary', '/multisite-radar/v1/alerts?order=asc&orderby=rule&page=1&per_page=20' ],
			Preload::paths( 'alerts', [], $prefs )
		);
		$this->assertSame( [ '/multisite-radar/v1/preferences', '/multisite-radar/v1/settings', '/multisite-radar/v1/alert-rules' ], Preload::paths( 'settings', [], $prefs ) );
	}

	public function test_the_reports_page_preloads_its_trends_changes_and_settings(): void {
		$prefs = Preferences::defaults();

		$this->assertSame(
			[
				'/multisite-radar/v1/preferences',
				'/multisite-radar/v1/reports/trends?days=90',
				'/multisite-radar/v1/events?page=1&per_page=20',
				'/multisite-radar/v1/settings',
			],
			Preload::paths( 'reports', [], $prefs )
		);
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

	public function test_a_route_that_throws_is_not_preloaded_and_is_reported(): void {
		$this->login_as_super_admin();
		$this->server->register_route(
			'msradar-test/v1',
			'/msradar-test/v1/boom',
			[
				[
					'methods'             => 'GET',
					'callback'            => static function (): void {
						throw new \Error( 'boom' );
					},
					'permission_callback' => '__return_true',
				],
			]
		);
		$errors = [];
		add_action(
			'msradar_error',
			static function ( string $context, \Throwable $error ) use ( &$errors ): void {
				$errors[] = [ $context, $error->getMessage() ];
			},
			10,
			2
		);

		$data = Preload::run( [ '/multisite-radar/v1/preferences', '/msradar-test/v1/boom', '/multisite-radar/v1/settings' ] );

		$this->assertSame( [ '/multisite-radar/v1/preferences', '/multisite-radar/v1/settings' ], array_keys( $data ), 'The other paths are still preloaded.' );
		$this->assertSame( [ [ Preload::class . '::run', 'boom' ] ], $errors );
	}
}
