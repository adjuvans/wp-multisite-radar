<?php
namespace MultisiteRadar\Tests\Admin;

use MultisiteRadar\Admin\Assets;
use MultisiteRadar\Admin\Menu;
use MultisiteRadar\Export\ExportHandler;
use MultisiteRadar\Tests\RestTestCase;

final class AssetsTest extends RestTestCase {

	private function assets(): Assets {
		return new Assets( new Menu(), $this->plugin()->preferences(), dirname( __DIR__ ) . '/fixtures/build/', 'https://example.test/build/' );
	}

	public function tear_down(): void {
		foreach ( [ 'msradar-sites', 'msradar-alerts', 'msradar-settings' ] as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
		parent::tear_down();
	}

	private function config_of( string $handle ): array {
		$inline = trim( implode( "\n", (array) wp_scripts()->get_data( $handle, 'before' ) ) );
		$this->assertStringStartsWith( 'window.msradarAdmin = ', $inline );
		$this->assertStringNotContainsString( '</script>', $inline );
		return json_decode( substr( $inline, strlen( 'window.msradarAdmin = ' ), -1 ), true );
	}

	public function test_enqueues_the_view_bundle_with_its_safe_inline_configuration(): void {
		$this->login_as_super_admin();
		$this->make_record(
			101,
			[
				'name'       => '</script><script>alert(1)</script>',
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);

		$this->assertTrue( $this->assets()->enqueue_view( 'sites' ) );

		$this->assertTrue( wp_script_is( 'msradar-sites', 'enqueued' ) );
		$this->assertSame( [ 'react', 'wp-api-fetch', 'wp-i18n' ], wp_scripts()->registered['msradar-sites']->deps );
		$this->assertSame( 'https://example.test/build/admin/sites.js', wp_scripts()->registered['msradar-sites']->src );
		$this->assertTrue( wp_style_is( 'msradar-sites', 'enqueued' ) );
		$this->assertSame( [ 'wp-components' ], wp_styles()->registered['msradar-sites']->deps );
		$this->assertSame( 'replace', wp_styles()->get_data( 'msradar-sites', 'rtl' ) );

		$config = $this->config_of( 'msradar-sites' );
		$this->assertSame( 'sites', $config['view'] );
		$this->assertTrue( $config['canManage'] );
		$this->assertSame( admin_url( 'admin-post.php' ), $config['exportUrl'] );
		$this->assertSame( 1, wp_verify_nonce( $config['exportNonce'], ExportHandler::ACTION ) );
		$this->assertSame( Menu::url( 'sites' ), $config['pages']['sites'] );
		$list = $config['preload']['/multisite-radar/v1/sites?order=asc&orderby=name&page=1&per_page=20'];
		$this->assertContains( '</script><script>alert(1)</script>', wp_list_pluck( $list['body'], 'name' ), 'The name survives intact once decoded.' );
	}

	public function test_a_throwing_preload_leaves_the_page_working_without_that_response(): void {
		$this->login_as_super_admin();
		// Filtre tiers défaillant sur une des réponses préchargées.
		add_filter(
			'rest_post_dispatch',
			static function ( $response, $server, $request ) {
				if ( '/multisite-radar/v1/alerts/summary' === $request->get_route() ) {
					throw new \Error( 'third-party filter' );
				}
				return $response;
			},
			10,
			3
		);
		$errors = did_action( 'msradar_error' );

		$config = $this->assets()->config( 'overview' );

		$this->assertSame( 'overview', $config['view'] );
		$this->assertSame( [ '/multisite-radar/v1/preferences', '/multisite-radar/v1/scan/status' ], array_keys( $config['preload'] ) );
		$this->assertSame( $errors + 1, did_action( 'msradar_error' ) );
	}

	public function test_a_missing_build_shows_a_notice_instead_of_a_broken_page(): void {
		$assets = $this->assets();

		$this->assertFalse( $assets->enqueue_view( 'alerts' ) );
		$this->assertFalse( wp_script_is( 'msradar-alerts', 'enqueued' ) );
		ob_start();
		$assets->render_missing_build_notice();
		$this->assertStringContainsString( 'npm run build', (string) ob_get_clean() );
	}

	public function test_the_settings_view_receives_post_types_and_plugins(): void {
		$this->login_as_super_admin();

		$config = $this->assets()->config( 'settings' );

		$this->assertContains(
			[
				'value' => 'post',
				'label' => 'Posts',
			],
			$config['postTypes']
		);
		$this->assertNotContains( 'attachment', array_column( $config['postTypes'], 'value' ) );
		$this->assertIsArray( $config['plugins'] );
		$this->assertArrayNotHasKey( 'postTypes', $this->assets()->config( 'sites' ) );
	}
}
