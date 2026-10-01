<?php
namespace MultisiteRadar;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4 : MultisiteRadar\Foo\Bar → includes/Foo/Bar.php.
 */
final class Autoloader {

	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				$prefix = __NAMESPACE__ . '\\';
				if ( 0 !== strpos( $class_name, $prefix ) ) {
					return;
				}
				$file = $base_dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
