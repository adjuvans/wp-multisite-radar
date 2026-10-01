<?php
namespace MultisiteRadar\Tests\Install;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Tests\TestCase;

final class SchemaTest extends TestCase {

	public function test_tables_exist_with_expected_columns(): void {
		global $wpdb;

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::sites_table() ) );
		foreach ( [ 'site_id', 'network_id', 'alert_level', 'alert_rules', 'registry_status', 'data', 'dirty', 'dirty_since', 'scanned_at' ] as $column ) {
			$this->assertContains( $column, $columns );
		}

		$this->assertSame(
			[ 'site_id', 'type', 'slug', 'role' ],
			$wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::extensions_table() ) )
		);
		$this->assertTrue( Schema::is_current() );
	}

	public function test_install_is_idempotent(): void {
		Schema::install();
		Schema::install();

		$this->assertTrue( Schema::is_current() );
	}
}
