<?php
// Amorçage PHPUnit : suite de tests WordPress en multisite, plugin chargé comme un MU-plugin.
$msradar_root = dirname( __DIR__, 2 );
require_once $msradar_root . '/vendor/autoload.php';

if ( false === getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

$msradar_tests_dir = (string) getenv( 'WP_PHPUNIT__DIR' );
require_once $msradar_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $msradar_root ): void {
		require $msradar_root . '/multisite-radar.php';
	}
);

require $msradar_tests_dir . '/includes/bootstrap.php';
