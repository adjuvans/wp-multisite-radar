<?php
namespace MultisiteRadar\Tests;

use MultisiteRadar\Install\Schema;

final class UninstallTest extends TestCase {

	public function test_uninstall_drops_every_table_of_the_schema(): void {
		global $wpdb;
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source file in a test.

		foreach ( Schema::tables() as $table ) {
			$this->assertStringContainsString( "'" . substr( $table, strlen( $wpdb->base_prefix ) ) . "'", $source, $table );
		}
	}
}
