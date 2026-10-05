<?php
namespace MultisiteRadar\Install;

use MultisiteRadar\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Activation, désactivation et mise à niveau du schéma.
 */
final class Installer {

	/**
	 * @param bool $network_wide Activation sur tout le réseau (passé par WordPress).
	 */
	public static function activate( $network_wide = false ): void {
		if ( ! is_multisite() ) {
			return;
		}
		if ( ! self::install() ) {
			return;
		}
		do_action( 'msradar_activated', (bool) $network_wide );
	}

	public static function deactivate(): void {
		do_action( 'msradar_deactivated' );
	}

	public static function maybe_upgrade(): void {
		if ( Schema::is_current() ) {
			return;
		}
		$previous = (int) get_site_option( Schema::OPTION, 0 );
		if ( ! self::install() ) {
			return;
		}
		do_action( 'msradar_upgraded', Schema::VERSION, $previous );
	}

	/**
	 * Crée ou met à jour les tables puis amorce le registre des sites.
	 *
	 * @return bool False si les tables n'ont pas pu être créées (rien n'est amorcé).
	 */
	private static function install(): bool {
		if ( ! Schema::install() ) {
			return false;
		}
		Plugin::instance()->sites()->seed_from_blogs( get_current_network_id() );
		return true;
	}
}
