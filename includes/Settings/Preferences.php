<?php
namespace MultisiteRadar\Settings;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Préférences d'affichage de chaque utilisateur, par vue : colonnes visibles, présentation, lignes par page.
 * Stockées dans la méta utilisateur msradar_view_prefs, commune à tout le réseau (les métas utilisateur sont globales).
 */
final class Preferences {

	public const META     = 'msradar_view_prefs';
	public const PER_PAGE = [ 10, 20, 50, 100 ];

	public static function defaults(): array {
		return [
			'sites'  => [
				'fields'   => [],
				'layout'   => 'table',
				'per_page' => 20,
			],
			'alerts' => [
				'fields'   => [],
				'per_page' => 20,
			],
		];
	}

	public static function schema(): array {
		$fields   = [
			'type'        => 'array',
			'maxItems'    => 40,
			'uniqueItems' => true,
			'items'       => [
				'type'    => 'string',
				'pattern' => '^[a-z0-9_]{1,40}$',
			],
		];
		$per_page = [
			'type' => 'integer',
			'enum' => self::PER_PAGE,
		];

		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'sites'  => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'fields'   => $fields,
						'layout'   => [
							'type' => 'string',
							'enum' => [ 'table', 'grid' ],
						],
						'per_page' => $per_page,
					],
				],
				'alerts' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'fields'   => $fields,
						'per_page' => $per_page,
					],
				],
			],
		];
	}

	public function get( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );
		return self::merge( self::defaults(), self::valid_views( is_array( $stored ) ? $stored : [] ) );
	}

	/**
	 * @return array|WP_Error Préférences complètes après mise à jour, ou erreur de validation (statut 400).
	 */
	public function update( int $user_id, array $patch ) {
		$schema = self::schema();
		$valid  = rest_validate_value_from_schema( $patch, $schema, 'preferences' );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'msradar_invalid_preferences', $valid->get_error_message(), [ 'status' => 400 ] );
		}
		$clean  = rest_sanitize_value_from_schema( $patch, $schema, 'preferences' );
		$stored = get_user_meta( $user_id, self::META, true );
		update_user_meta(
			$user_id,
			self::META,
			self::merge( self::valid_views( is_array( $stored ) ? $stored : [] ), is_array( $clean ) ? $clean : [] )
		);
		return $this->get( $user_id );
	}

	/**
	 * Vues valides d'une valeur enregistrée : une vue corrompue retombe sur ses valeurs par défaut, sans toucher aux autres.
	 */
	private static function valid_views( array $stored ): array {
		$views = [];
		foreach ( self::schema()['properties'] as $view => $view_schema ) {
			if ( isset( $stored[ $view ] ) && is_array( $stored[ $view ] ) && true === rest_validate_value_from_schema( $stored[ $view ], $view_schema, $view ) ) {
				$views[ $view ] = $stored[ $view ];
			}
		}
		return $views;
	}

	/**
	 * Fusion à deux niveaux : chaque vue fusionne ses clés ; une liste (fields) est remplacée, pas fusionnée.
	 */
	private static function merge( array $base, array $patch ): array {
		foreach ( $patch as $view => $values ) {
			$base[ $view ] = array_merge( (array) ( $base[ $view ] ?? [] ), (array) $values );
		}
		return $base;
	}
}
