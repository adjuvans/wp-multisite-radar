<?php
namespace MultisiteRadar\Tests\Reports;

use MultisiteRadar\Reports\Digest;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Tests\TestCase;

final class DigestTest extends TestCase {

	private const NOW = 1789560000; // Mercredi 16 septembre 2026, 12:00 UTC (fuseau des tests : UTC).

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		delete_site_option( Digest::SENT_OPTION );
		$this->make_record(
			5101,
			[
				'name'         => 'R&amp;D <script>alert(1)</script>',
				'scanned_at'   => '2026-09-01 00:00:00',
				'alert_level'  => 3,
				'alerts_count' => 1,
				'alert_rules'  => ',no_users,',
			]
		);
		$event = static function ( string $type, string $subject, string $created_at ): array {
			return [
				'network_id' => get_current_network_id(),
				'site_id'    => 5101,
				'type'       => $type,
				'subject'    => $subject,
				'meta'       => [],
				'created_at' => $created_at,
			];
		};
		$this->plugin()->events()->insert(
			[
				$event( 'alert_raised', 'no_users', '2026-09-15 08:00:00' ),
				$event( 'alert_resolved', 'inactive', '2026-09-14 08:00:00' ),
				$event( 'plugin_activated', 'akismet/akismet.php', '2026-09-13 08:00:00' ),
				$event( 'alert_raised', 'search_hidden', '2026-08-01 08:00:00' ),
			]
		);
	}

	private function enable( array $recipients = [] ): void {
		$this->plugin()->settings()->update(
			[
				'reports' => [
					'digest_enabled'    => true,
					'digest_day'        => 3,
					'digest_recipients' => [] !== $recipients ? $recipients : [
						'mode'   => 'custom',
						'emails' => [ 'one@example.org', 'two@example.org' ],
					],
				],
			]
		);
	}

	/**
	 * @return array[]
	 */
	private function sent(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	public function test_nothing_is_sent_while_the_digest_is_off_or_on_another_day(): void {
		$this->plugin()->digest()->maybe_send( self::NOW );
		$this->assertSame( [], $this->sent() );

		$this->enable();
		$this->plugin()->digest()->maybe_send( self::NOW + DAY_IN_SECONDS );
		$this->assertSame( [], $this->sent() );
	}

	public function test_each_recipient_gets_its_own_e_mail_once_on_the_chosen_day(): void {
		$this->enable();
		$this->plugin()->digest()->maybe_send( self::NOW );
		$this->plugin()->digest()->maybe_send( self::NOW + HOUR_IN_SECONDS );

		$sent = $this->sent();
		$this->assertCount( 2, $sent, 'One e-mail per recipient, and not twice the same day.' );
		$this->assertSame( 'one@example.org', $sent[0]['to'][0][0] );
		$this->assertSame( 'two@example.org', $sent[1]['to'][0][0] );
		$this->assertCount( 1, $sent[0]['to'], 'A recipient never sees the other addresses.' );
		$this->assertSame( '2026-09-16', get_site_option( Digest::SENT_OPTION ) );
	}

	public function test_the_content_lists_the_alerts_of_the_week_with_plain_escaped_names(): void {
		$mail = $this->plugin()->digest()->compose( self::NOW );

		$this->assertStringContainsString( 'Multisite Radar', $mail['subject'] );
		$this->assertStringContainsString( 'R&amp;D &lt;script&gt;', $mail['html'] );
		$this->assertStringNotContainsString( '<script>', $mail['html'] );
		$this->assertStringContainsString( 'Site without users', $mail['html'] );
		$this->assertStringContainsString( 'Inactive site', $mail['html'] );
		$this->assertStringNotContainsString( 'Hidden from search engines', $mail['html'], 'Older than seven days.' );
		$this->assertStringContainsString( 'page=multisite-radar', $mail['html'] );
		$this->assertStringContainsString( 'R&D <script>alert(1)</script>', $mail['text'] );
		$this->assertStringContainsString( '1 plugin activated', $mail['text'] );
	}

	public function test_the_text_alternative_is_attached(): void {
		$this->enable(
			[
				'mode'   => 'custom',
				'emails' => [ 'one@example.org' ],
			]
		);
		$this->plugin()->digest()->maybe_send( self::NOW );

		$this->assertStringContainsString( 'Open Multisite Radar', tests_retrieve_phpmailer_instance()->AltBody );
		$this->assertStringContainsString( 'multipart/alternative', $this->sent()[0]['header'] );
	}

	public function test_super_admins_by_default_and_no_valid_address_means_no_mail(): void {
		$admin = self::factory()->user->create( [ 'user_email' => 'chief@example.org' ] );
		grant_super_admin( $admin );
		$this->enable(
			[
				'mode'   => 'super_admins',
				'emails' => [],
			]
		);
		$this->assertContains( 'chief@example.org', $this->plugin()->digest()->recipients() );

		$this->enable(
			[
				'mode'   => 'custom',
				'emails' => [],
			]
		);
		$this->plugin()->digest()->maybe_send( self::NOW );
		$this->assertSame( [], $this->sent() );
		$this->assertFalse( get_site_option( Digest::SENT_OPTION ) );
	}

	public function test_a_failed_send_is_not_recorded_as_sent(): void {
		$this->enable(
			[
				'mode'   => 'custom',
				'emails' => [ 'one@example.org' ],
			]
		);
		add_filter( 'pre_wp_mail', '__return_false' );
		try {
			$this->plugin()->digest()->maybe_send( self::NOW );
		} finally {
			remove_filter( 'pre_wp_mail', '__return_false' );
		}

		$this->assertFalse( get_site_option( Digest::SENT_OPTION ) );
	}

	public function test_a_storage_failure_is_reported_and_never_reaches_the_daily_task(): void {
		global $wpdb;
		$this->enable();
		$reported = [];
		$report   = static function ( string $context ) use ( &$reported ): void {
			$reported[] = $context;
		};
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'msradar_events' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		add_action( 'msradar_error', $report );
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->plugin()->digest()->maybe_send( self::NOW );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break );
			remove_action( 'msradar_error', $report );
		}

		$this->assertSame( [ Digest::class . '::maybe_send' ], $reported );
		$this->assertSame( [], $this->sent() );
	}

	public function test_it_runs_on_the_daily_task_after_the_history(): void {
		$this->assertSame( 30, has_action( Queue::HOOK_DAILY, [ $this->plugin()->digest(), 'maybe_send' ] ) );
	}
}
