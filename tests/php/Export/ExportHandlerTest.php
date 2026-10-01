<?php
namespace MultisiteRadar\Tests\Export;

use MultisiteRadar\Export\ExportHandler;
use MultisiteRadar\Export\SitesColumns;
use MultisiteRadar\Tests\TestCase;
use WPDieException;

final class ExportHandlerTest extends TestCase {

	private ExportHandler $handler;

	public function set_up(): void {
		parent::set_up();
		$this->handler = $this->plugin()->export();
		$scanned       = '2026-09-01 00:00:00';
		$this->make_record( 801, [ 'name' => 'Alpha', 'url' => 'https://alpha.test/', 'users_count' => 3, 'scanned_at' => $scanned, 'theme_stylesheet' => 'astra' ] );
		$this->make_record( 802, [ 'name' => '=HYPERLINK("http://evil.test")', 'url' => 'https://beta.test/', 'alert_level' => 3, 'alerts_count' => 1, 'alert_rules' => ',no_users,', 'scanned_at' => $scanned, 'is_archived' => true ] );
	}

	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'], $_GET['_wpnonce'] );
		parent::tear_down();
	}

	private function export( array $input ): string {
		$params = $this->handler->params( $input );
		$this->assertIsArray( $params );
		$stream = fopen( 'php://memory', 'w+b' );
		$this->handler->write( $params, $stream );
		rewind( $stream );
		return (string) stream_get_contents( $stream );
	}

	public function test_params_validate_the_resource_format_fields_and_filters(): void {
		$this->assertSame( 'msradar_unknown_resource', $this->handler->params( [ 'resource' => 'users' ] )->get_error_code() );
		$this->assertSame( 'msradar_unknown_format', $this->handler->params( [ 'format' => 'xlsx' ] )->get_error_code() );

		$params = $this->handler->params(
			[
				'format'      => 'json',
				'fields'      => 'name,bogus,id',
				'alert_level' => 'error,warning',
				'include'     => [ '802', '0', 'x' ],
				'search'      => '',
			]
		);
		$this->assertSame( 'sites', $params['resource'] );
		$this->assertSame( 'json', $params['format'] );
		$this->assertSame( [ 'id', 'name' ], $params['fields'], 'Known fields only, in canonical order.' );
		$this->assertSame(
			[
				'alert_level' => [ 'error', 'warning' ],
				'include'     => [ 802, 0, 0 ],
			],
			$params['filters']
		);
		$this->assertSame( array_keys( SitesColumns::all() ), $this->handler->params( [] )['fields'], 'No fields means every column.' );
	}

	public function test_non_scalar_input_for_a_scalar_filter_is_ignored(): void {
		$params = $this->handler->params( [ 'search' => [ 'x' ], 'theme' => [ 'astra' ], 'order' => 'asc' ] );

		$this->assertSame( [ 'order' => 'asc' ], $params['filters'] );
	}

	public function test_the_search_is_passed_through_unchanged(): void {
		$params = $this->handler->params( [ 'search' => '100%  %41 "x"' ] );

		$this->assertSame( [ 'search' => '100%  %41 "x"' ], $params['filters'] );
	}

	public function test_csv_export_applies_filters_and_neutralises_formulas(): void {
		$csv = $this->export(
			[
				'format' => 'csv',
				'fields' => 'id,name,status,alert_rules',
				'status' => 'archived',
			]
		);

		$this->assertSame( "\xEF\xBB\xBFID,Name,Status,\"Alert rules\"\n802,\"'=HYPERLINK(\"\"http://evil.test\"\")\",\"public,archived\",no_users\n", $csv );
	}

	public function test_json_export_has_a_meta_block_and_typed_values(): void {
		$data = json_decode( $this->export( [ 'format' => 'json', 'fields' => 'id,users_count,disk_bytes', 'include' => '801' ] ), true );

		$this->assertSame( 'sites', $data['meta']['resource'] );
		$this->assertSame( MSRADAR_VERSION, $data['meta']['version'] );
		$this->assertSame( [ 'include' => [ 801 ] ], $data['meta']['filters'] );
		$this->assertSame( [ 'id', 'users_count', 'disk_bytes' ], $data['meta']['fields'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $data['meta']['generated_gmt'] );
		$this->assertSame( [ [ 'id' => 801, 'users_count' => 3, 'disk_bytes' => null ] ], $data['items'] );
	}

	public function test_the_file_name_is_timestamped_in_utc(): void {
		$this->assertMatchesRegularExpression( '/^multisite-radar-sites-\d{8}-\d{6}\.csv$/', $this->handler->filename( 'sites', 'csv' ) );
	}

	public function test_handle_requires_a_valid_nonce(): void {
		$this->login_as( true );
		$_REQUEST['_wpnonce'] = 'not-a-nonce';

		$this->expectException( WPDieException::class );
		$this->handler->handle();
	}

	public function test_handle_requires_the_view_capability(): void {
		$this->login_as( false );
		$_REQUEST['_wpnonce'] = wp_create_nonce( ExportHandler::ACTION );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'not allowed' );
		$this->handler->handle();
	}

	public function test_a_failed_read_while_streaming_ends_with_wp_die_and_reports_the_error(): void {
		global $wpdb;
		$this->login_as( true );
		$_REQUEST['_wpnonce'] = wp_create_nonce( ExportHandler::ACTION );
		$_GET                 = [ 'format' => 'json' ];
		$reported             = [];
		$report               = static function ( string $context, \Throwable $error ) use ( &$reported ): void {
			$reported[] = $context;
		};
		$guard                = static fn ( string $query ): string => false !== strpos( $query, $wpdb->base_prefix . 'msradar_sites' ) ? 'SELECT * FROM msradar_no_such_table' : $query;
		add_action( 'msradar_error', $report, 10, 2 );
		add_filter( 'query', $guard );
		$previous = $wpdb->suppress_errors( true );
		ob_start();

		try {
			$this->handler->handle();
			$this->fail( 'wp_die() was expected.' );
		} catch ( WPDieException $die ) {
			$this->assertStringContainsString( 'interrupted', $die->getMessage() );
		} finally {
			ob_end_clean();
			$wpdb->suppress_errors( $previous );
			remove_filter( 'query', $guard );
			remove_action( 'msradar_error', $report, 10 );
			$_GET = [];
		}

		$this->assertSame( [ ExportHandler::class . '::handle' ], $reported );
	}

	private function login_as( bool $super_admin ): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		if ( $super_admin ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );
	}
}
