<?php
namespace MultisiteRadar\Tests;

/**
 * Plugin Check (CI, bloquant pour WordPress.org) ne cherche le garde d'accès direct que dans les 50 premières lignes
 * d'un fichier PHP.
 */
final class DirectAccessGuardTest extends TestCase {

	public function test_every_php_file_guards_direct_access_within_its_first_lines(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array_merge( [ $root . '/multisite-radar.php' ], $this->php_files( $root . '/includes' ) );
		foreach ( $files as $file ) {
			$head = implode( "\n", array_slice( (array) file( $file ), 0, 50 ) );
			$this->assertMatchesRegularExpression( "/defined\(\s*'ABSPATH'\s*\)\s*\|\|\s*exit;/", $head, $file );
		}
	}

	/**
	 * @return string[]
	 */
	private function php_files( string $dir ): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}
}
