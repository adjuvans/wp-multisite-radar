<?php
namespace MultisiteRadar\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Valeur saisie en ligne de commande.
 */
final class Values {

	/**
	 * Du JSON si c'en est (true, 14, ["post","page"], {"enabled":false}, null), le texte tel quel sinon.
	 *
	 * @return mixed
	 */
	public static function parse( string $raw ) {
		$decoded = json_decode( $raw, true );
		if ( null === $decoded && 'null' !== trim( $raw ) ) {
			return $raw;
		}
		return $decoded;
	}
}
