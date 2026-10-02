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

	private function break_sites_reads( int $after = 0 ): callable {
		global $wpdb;
		$seen = 0;
		return static function ( string $query ) use ( $wpdb, &$seen, $after ): string {
			if ( false !== strpos( $query, $wpdb->base_prefix . 'msradar_sites' ) && 0 === strpos( ltrim( $query ), 'SELECT' ) && ++$seen > $after ) {
				return 'SELECT * FROM msradar_no_such_table';
			}
			return $query;
		};
	}

	public function test_a_failed_first_read_is_a_real_500_before_any_output(): void {
		global $wpdb;
		$this->login_as( true );
		$_REQUEST['_wpnonce'] = wp_create_nonce( ExportHandler::ACTION );
		$_GET                 = [ 'format' => 'csv' ];
		$reported             = [];
		$report               = static function ( string $context ) use ( &$reported ): void {
			$reported[] = $context;
		};
		$guard                = $this->break_sites_reads();
		add_action( 'msradar_error', $report );
		add_filter( 'query', $guard );
		$previous = $wpdb->suppress_errors( true );
		ob_start();

		try {
			$this->handler->handle();
			$this->fail( 'wp_die() was expected.' );
		} catch ( WPDieException $die ) {
			$this->assertSame( 500, $die->getCode() );
			$this->assertStringContainsString( 'could not be read', $die->getMessage() );
		} finally {
			$output = ob_get_clean();
			$wpdb->suppress_errors( $previous );
			remove_filter( 'query', $guard );
			remove_action( 'msradar_error', $report );
			$_GET = [];
		}

		$this->assertSame( '', $output, 'No BOM, header row or markup was written.' );
		$this->assertSame( [ ExportHandler::class . '::handle' ], $reported );
	}

	public function test_plugins_and_themes_are_exported_with_their_own_filters_and_columns(): void {
		wp_cache_set(
			'plugins',
			[
				'' => [
					'alpha/alpha.php' => [
						'Name'    => '=Alpha',
						'Version' => '1.0',
					],
					'beta/beta.php'   => [
						'Name'    => 'Beta',
						'Version' => '2.0',
					],
				],
			],
			'plugins'
		);
		$this->plugin()->extensions()->replace_for_site( 801, [ 'alpha/alpha.php' ], '', '' );

		$csv = $this->export(
			[
				'resource' => 'plugins',
				'format'   => 'csv',
				'fields'   => 'name,file,sites_count,network_active',
				'status'   => 'local',
			]
		);
		$this->assertSame( "\xEF\xBB\xBFName,\"Plugin file\",\"Network activated\",Sites\n'=Alpha,alpha/alpha.php,0,1\n", $csv );

		$data = json_decode(
			$this->export(
				[
					'resource' => 'themes',
					'format'   => 'json',
					'fields'   => 'stylesheet,sites_count',
					'search'   => 'astra',
				]
			),
			true
		);
		$this->assertSame( 'themes', $data['meta']['resource'] );
		$this->assertSame( [ 'search' => 'astra' ], $data['meta']['filters'] );
		$this->assertSame(
			[
				[
					'stylesheet'  => 'astra',
					'sites_count' => 1,
				],
			],
			$data['items']
		);
	}

	public function test_each_resource_accepts_only_its_own_filters_and_columns(): void {
		$params = $this->handler->params(
			[
				'resource'    => 'plugins',
				'has_update'  => '1',
				'alert_level' => 'error',
				'fields'      => 'bogus',
			]
		);

		$this->assertSame( [ 'has_update' => '1' ], $params['filters'] );
		$this->assertSame( [ 'name', 'file', 'version', 'status', 'network_active', 'sites_count', 'update_version' ], $params['fields'] );
	}

	/**
	 * @dataProvider formats
	 */
	public function test_a_failure_from_the_second_chunk_ends_the_file_with_a_visible_marker( string $format ): void {
		global $wpdb;
		$params = $this->handler->params(
			[
				'format' => $format,
				'fields' => 'id',
			]
		);
		$stream = fopen( 'php://memory', 'w+b' );
		$fired  = [];
		$report = static function ( string $context ) use ( &$fired ): void {
			$fired[] = $context;
		};
		$guard  = $this->break_sites_reads( 2 ); // Each page reads twice: the count, then the rows.
		add_action( 'msradar_error', $report );
		add_filter( 'query', $guard );
		$previous = $wpdb->suppress_errors( true );

		try {
			$completed = $this->handler->stream( $params, $stream, 1 );
		} finally {
			$wpdb->suppress_errors( $previous );
			remove_filter( 'query', $guard );
			remove_action( 'msradar_error', $report );
		}
		rewind( $stream );
		$output = (string) stream_get_contents( $stream );

		$this->assertFalse( $completed );
		$this->assertSame( [ ExportHandler::class . '::stream' ], $fired );
		$this->assertStringNotContainsString( '<', $output );
		$this->assertStringNotContainsString( 'wp-die', $output );
		if ( 'csv' === $format ) {
			$lines = explode( "\n", trim( $output ) );
			$this->assertGreaterThanOrEqual( 3, count( $lines ), 'Header, first chunk, then the marker.' );
			$this->assertSame( [ ExportHandler::incomplete_notice() ], str_getcsv( (string) end( $lines ), ',', '"', '' ) );
		} else {
			$data = json_decode( $output, true );
			$this->assertIsArray( $data, 'The document stays valid JSON.' );
			$this->assertNotEmpty( $data['items'], 'The first chunk was streamed before the failure.' );
			$this->assertTrue( $data['incomplete'] );
			$this->assertSame( ExportHandler::incomplete_notice(), $data['error'] );
		}
	}

	public static function formats(): array {
		return [
			'csv'  => [ 'csv' ],
			'json' => [ 'json' ],
		];
	}

	public function test_nested_arrays_in_list_parameters_are_ignored(): void {
		$params = $this->handler->params( [ 'fields' => [ [ 'x' ], 'id' ], 'alert_level' => [ [ 'x' ], 'error' ] ] );

		$this->assertSame( [ 'id' ], $params['fields'] );
		$this->assertSame( [ 'alert_level' => [ 'error' ] ], $params['filters'] );
	}

	private function login_as( bool $super_admin ): void {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		if ( $super_admin ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );
	}
}
