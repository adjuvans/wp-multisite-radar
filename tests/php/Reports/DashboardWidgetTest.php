<?php
namespace MultisiteRadar\Tests\Reports;

use MultisiteRadar\Reports\DashboardWidget;
use MultisiteRadar\Tests\TestCase;

final class DashboardWidgetTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard-network' );
		$this->make_record(
			5201,
			[
				'name'         => 'R&amp;D <script>alert(1)</script>',
				'scanned_at'   => '2026-09-01 00:00:00',
				'alert_level'  => 3,
				'alerts_count' => 1,
				'alert_rules'  => ',no_users,',
			]
		);
	}

	public function tear_down(): void {
		$GLOBALS['wp_meta_boxes'] = [];
		set_current_screen( 'front' );
		wp_dequeue_style( DashboardWidget::STYLE_HANDLE );
		wp_deregister_style( DashboardWidget::STYLE_HANDLE );
		parent::tear_down();
	}

	private function render(): string {
		ob_start();
		$this->plugin()->dashboard_widget()->render();
		return (string) ob_get_clean();
	}

	public function test_the_widget_is_offered_to_users_with_the_view_capability_only(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->plugin()->dashboard_widget()->add();
		$this->assertFalse( isset( $GLOBALS['wp_meta_boxes']['dashboard-network']['normal']['core'][ DashboardWidget::ID ] ) );

		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );
		$this->plugin()->dashboard_widget()->add();
		$this->assertTrue( isset( $GLOBALS['wp_meta_boxes']['dashboard-network']['normal']['core'][ DashboardWidget::ID ] ) );
	}

	public function test_it_shows_the_key_figures_and_the_main_alerts_escaped(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Sites with an error', $html );
		$this->assertStringContainsString( 'R&amp;D &lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'site=5201', $html );
		$this->assertStringContainsString( 'page=multisite-radar', $html );
	}

	public function test_the_links_inside_a_sentence_are_underlined(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<a href="[^"]*site=5201[^"]*" style="text-decoration: underline;">/', $html );
	}

	public function test_each_tile_leads_to_the_filtered_screen(): void {
		$html = $this->render();

		$this->assertSame( 5, substr_count( $html, 'class="msradar-widget__tile"' ) );
		foreach ( [ 'alert_level=error', 'alert_level=warning', 'status=unused', 'has_update=1', 'page=multisite-radar-sites' ] as $fragment ) {
			$this->assertStringContainsString( $fragment, $html );
		}
	}

	public function test_main_alerts_carry_their_severity(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<span class="msradar-widget__severity msradar-widget__severity--(error|warning|info)">[^<]+<\/span>/', $html );
	}

	public function test_its_style_is_loaded_with_the_widget_only(): void {
		$user = self::factory()->user->create();
		grant_super_admin( $user );
		wp_set_current_user( $user );

		$this->plugin()->dashboard_widget()->add();

		$this->assertTrue( wp_style_is( DashboardWidget::STYLE_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString( '.msradar-widget__tiles', implode( '', (array) wp_styles()->get_data( DashboardWidget::STYLE_HANDLE, 'after' ) ) );
	}

	public function test_a_failed_read_shows_a_message_instead_of_breaking_the_dashboard(): void {
		global $wpdb;
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'AS with_alerts' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$html = $this->render();
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertStringContainsString( 'could not read its data', $html );
	}

	public function test_an_exception_thrown_by_a_third_party_filter_shows_the_message(): void {
		$reported = [];
		$report   = static function ( string $context ) use ( &$reported ): void {
			$reported[] = $context;
		};
		$boom     = static function () {
			throw new \Exception( 'boom' );
		};
		add_action( 'msradar_error', $report );
		add_filter( 'site_transient_update_plugins', $boom );
		try {
			$html = $this->render();
		} finally {
			remove_filter( 'site_transient_update_plugins', $boom );
			remove_action( 'msradar_error', $report );
		}

		$this->assertStringContainsString( 'could not read its data', $html );
		$this->assertSame( [ DashboardWidget::class . '::render' ], $reported );
	}

	public function test_it_is_hooked_on_the_network_dashboard(): void {
		$this->assertSame( 10, has_action( 'wp_network_dashboard_setup', [ $this->plugin()->dashboard_widget(), 'add' ] ) );
	}
}
