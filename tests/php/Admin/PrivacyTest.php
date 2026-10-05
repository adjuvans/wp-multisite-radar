<?php
namespace MultisiteRadar\Tests\Admin;

use MultisiteRadar\Admin\Privacy;
use MultisiteRadar\Tests\TestCase;

final class PrivacyTest extends TestCase {

	public function test_registers_a_privacy_policy_text_on_admin_init(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'dashboard' );
		$privacy = new Privacy();

		// Fire admin_init with only our callback: core's own callbacks send HTTP headers, which the test runner has already started output for.
		$saved = $GLOBALS['wp_filter']['admin_init'] ?? null;
		unset( $GLOBALS['wp_filter']['admin_init'] );
		try {
			$privacy->register();
			$this->assertNotFalse( has_action( 'admin_init', [ $privacy, 'add_policy_content' ] ) );
			do_action( 'admin_init' );
		} finally {
			unset( $GLOBALS['wp_filter']['admin_init'] );
			if ( null !== $saved ) {
				$GLOBALS['wp_filter']['admin_init'] = $saved;
			}
		}

		$texts = array_column( \WP_Privacy_Policy_Content::get_suggested_policy_text(), 'policy_text', 'plugin_name' );
		$this->assertArrayHasKey( 'Multisite Radar', $texts );
		$this->assertStringContainsString( 'logins', $texts['Multisite Radar'] );
		$this->assertStringContainsString( 'recipients', $texts['Multisite Radar'] );
	}
}
