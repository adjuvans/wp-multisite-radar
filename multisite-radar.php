<?php
/**
 * Plugin Name:       Multisite Radar
 * Plugin URI:        https://github.com/adjuvans/wp-multisite-radar
 * Description:       Network-wide audit for WordPress Multisite: sites, content types, users, plugins, themes and health alerts.
 * Version:           2.0.0-beta.4
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            ADJUVANS
 * Author URI:        https://adjuvans.fr
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       multisite-radar
 * Network:           true
 *
 * @package MultisiteRadar
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'MSRADAR_VERSION' ) ) {
	return;
}

define( 'MSRADAR_VERSION', '2.0.0-beta.4' );
define( 'MSRADAR_FILE', __FILE__ );
define( 'MSRADAR_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSRADAR_URL', plugin_dir_url( __FILE__ ) );

require_once MSRADAR_DIR . 'includes/Autoloader.php';
MultisiteRadar\Autoloader::register( MSRADAR_DIR . 'includes/' );

register_activation_hook( __FILE__, [ MultisiteRadar\Install\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ MultisiteRadar\Install\Installer::class, 'deactivate' ] );

MultisiteRadar\Plugin::instance()->boot();
