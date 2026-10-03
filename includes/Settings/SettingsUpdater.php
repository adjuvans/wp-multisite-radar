<?php
namespace MultisiteRadar\Settings;

use MultisiteRadar\Alerts\RuleRegistry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Applique une modification des réglages venue de l'extérieur (route REST, WP-CLI) : chaque règle d'alertes nommée doit
 * exister et ses paramètres suivre le schéma de la règle ; Settings::update() valide ensuite le reste et enregistre.
 * Rien n'est enregistré si une partie est refusée.
 */
final class SettingsUpdater {

	private Settings $settings;
	private RuleRegistry $rules;

	public function __construct( Settings $settings, RuleRegistry $rules ) {
		$this->settings = $settings;
		$this->rules    = $rules;
	}

	/**
	 * @return array|WP_Error Réglages complets après mise à jour, ou erreur (statut 400).
	 */
	public function apply( array $patch ) {
		$rules = $patch['alerts']['rules'] ?? [];
		foreach ( is_array( $rules ) ? $rules : [] as $rule_id => $config ) {
			$rule = $this->rules->get( (string) $rule_id );
			if ( null === $rule ) {
				return new WP_Error(
					'msradar_unknown_rule',
					/* translators: %s: alert rule identifier. */
					sprintf( __( 'Unknown alert rule: %s', 'multisite-radar' ), (string) $rule_id ),
					[ 'status' => 400 ]
				);
			}
			if ( is_array( $config ) && array_key_exists( 'params', $config ) ) {
				$valid = rest_validate_value_from_schema( $config['params'], $rule->params_schema(), 'params' );
				if ( is_wp_error( $valid ) ) {
					return new WP_Error( 'msradar_invalid_settings', $valid->get_error_message(), [ 'status' => 400 ] );
				}
			}
		}

		return $this->settings->update( $patch );
	}

	/**
	 * Modification qui porte $value au chemin pointé $path, ex. « scan.full_rescan_days » → [ 'scan' => [ … ] ].
	 *
	 * @param string $path  Chemin pointé.
	 * @param mixed  $value Nouvelle valeur.
	 * @return array|null Null si le chemin est vide ou contient un segment vide.
	 */
	public static function patch_for( string $path, $value ): ?array {
		$keys = explode( '.', $path );
		if ( in_array( '', $keys, true ) ) {
			return null;
		}
		$patch = $value;
		foreach ( array_reverse( $keys ) as $key ) {
			$patch = [ $key => $patch ];
		}
		return $patch;
	}
}
