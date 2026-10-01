<?php
namespace MultisiteRadar\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Texte brut à partir d'une valeur que WordPress stocke en HTML : le titre d'un site passe par esc_html() à
 * l'enregistrement (L'atelier R&D devient L&#039;atelier R&amp;D), le nom d'un thème par wp_kses().
 * Le plugin stocke et sert le texte brut ; l'échappement se fait une seule fois, à l'affichage.
 */
final class PlainText {

	public static function from_html( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
