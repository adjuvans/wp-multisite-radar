<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Plugin;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Base des commandes : accès aux services. WP-CLI fait une sous-commande de chaque méthode publique : les aides
 * restent donc protégées.
 */
abstract class Command {

	protected Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Une lecture a échoué : l'erreur est signalée à msradar_error, puis la commande s'arrête (code de sortie 1).
	 */
	protected static function read_failed( string $context, \RuntimeException $error ): void {
		do_action( 'msradar_error', $context, $error );
		WP_CLI::error( 'Multisite Radar could not read its data. Try again in a moment.' );
	}
}
