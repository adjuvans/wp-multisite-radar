<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class ReportsControllerTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->make_record(
			4901,
			[
				'name'       => 'Reported',
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);
		$this->make_record(
			4902,
			[
				'network_id' => 2,
				'scanned_at' => '2026-09-01 00:00:00',
			]
		);
		$this->plugin()->snapshots()->capture( get_current_network_id(), gmdate( 'Y-m-d' ) );
	}

	public function test_trends_of_the_network_and_of_a_site_for_the_view_capability(): void {
		$this->assertSame( 401, $this->request( 'GET', '/reports/trends' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/reports/trends' )->get_status() );

		$this->login_as_super_admin();
		$network = $this->request( 'GET', '/reports/trends' );
		$this->assertSame( 200, $network->get_status() );
		$this->assertSame( 90, $network->get_data()['days'] );
		$this->assertSame( [ gmdate( 'Y-m-d' ) ], array_column( $network->get_data()['points'], 'day' ) );

		$site = $this->request(
			'GET',
			'/reports/trends',
			[
				'site' => 4901,
				'days' => 7,
			]
		);
		$this->assertSame( 200, $site->get_status() );
		$this->assertSame( 4901, $site->get_data()['site'] );

		$this->assertSame( 404, $this->request( 'GET', '/reports/trends', [ 'site' => 4902 ] )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/reports/trends', [ 'days' => 1 ] )->get_status() );
	}

	public function test_a_failed_read_is_a_500(): void {
		global $wpdb;
		$this->login_as_super_admin();
		$break    = static function ( string $query ): string {
			return false !== strpos( $query, 'msradar_snapshots' ) ? 'SELECT * FROM msradar_missing_table' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$response = $this->request( 'GET', '/reports/trends' );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $response->get_status() );
	}

	public function test_a_test_digest_goes_to_the_current_user_only(): void {
		reset_phpmailer_instance();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'POST', '/reports/digest/test' )->get_status() );

		$user = self::factory()->user->create( [ 'user_email' => 'tester@example.org' ] );
		grant_super_admin( $user );
		wp_set_current_user( $user );
		$response = $this->request( 'POST', '/reports/digest/test' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'sent' => true ], $response->get_data() );
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertSame( 'tester@example.org', $sent[0]['to'][0][0] );
	}

	public function test_an_exception_thrown_while_sending_the_test_digest_becomes_a_mail_error(): void {
		$user = self::factory()->user->create( [ 'user_email' => 'tester@example.org' ] );
		grant_super_admin( $user );
		wp_set_current_user( $user );
		$boom = static function (): void {
			throw new \PHPMailer\PHPMailer\Exception( 'boom' );
		};
		add_action( 'phpmailer_init', $boom );
		try {
			$response = $this->request( 'POST', '/reports/digest/test' );
		} finally {
			remove_action( 'phpmailer_init', $boom );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'msradar_mail_failed', $response->get_data()['code'] );
		$this->assertStringNotContainsString( '@', (string) wp_json_encode( $response->get_data() ) );
	}
}
