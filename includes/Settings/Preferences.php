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
		$list = [
			'fields'   => [],
			'per_page' => 20,
		];
		return [
			'sites'   => [
				'fields'   => [],
				'layout'   => 'table',
				'per_page' => 20,
			],
			'plugins' => $list,
			'themes'  => $list,
			'users'   => $list,
			'alerts'  => $list,
		];
	}

	public static function schema(): array {
		$fields   = [
			'type'        => 'array',
			'maxItems'    => 40,
			'uniqueItems' => true,
			'items'       => [
				'type'    => 'string',
				'pattern' => '^[a-z0-9_]{1,40}\z',
			],
		];
		$per_page = [
			'type' => 'integer',
			'enum' => self::PER_PAGE,
		];

		$list = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'fields'   => $fields,
				'per_page' => $per_page,
			],
		];

		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'sites'   => [
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
				'plugins' => $list,
				'themes'  => $list,
				'users'   => $list,
				'alerts'  => $list,
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
	 * Mise à niveau du schéma : une colonne ajoutée aux valeurs par défaut d'une vue apparaît aussi chez ceux qui ont
	 * déjà choisi leurs colonnes. 5 : nom public et e-mail dans Comptes (écart E3 du plan rc.2).
	 *
	 * @param int|string $version  Nouvelle version du schéma.
	 * @param int|string $previous Version précédente (0 : première installation, sans préférences).
	 */
	public function on_upgraded( $version = 0, $previous = 0 ): void {
		if ( (int) $previous > 0 && (int) $previous < 5 ) {
			$this->add_fields( 'users', [ 'display_name', 'email' ] );
		}
	}

	/**
	 * Ajoute des colonnes, en tête et sans doublon, à la liste enregistrée d'une vue, pour chaque compte qui en a
	 * choisi une. Une liste vide (valeurs par défaut) ne change pas.
	 *
	 * @param string[] $fields
	 */
	public function add_fields( string $view, array $fields ): void {
		$users = get_users(
			[
				'blog_id'  => 0,
				'meta_key' => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off schema upgrade over the few accounts that saved display preferences.
				'fields'   => 'ID',
				'number'   => -1,
			]
		);
		foreach ( $users as $user_id ) {
			$stored = get_user_meta( (int) $user_id, self::META, true );
			if ( ! is_array( $stored ) || ! isset( $stored[ $view ]['fields'] ) || ! is_array( $stored[ $view ]['fields'] ) || [] === $stored[ $view ]['fields'] ) {
				continue;
			}
			$missing = array_values( array_diff( $fields, $stored[ $view ]['fields'] ) );
			if ( [] === $missing ) {
				continue;
			}
			$stored[ $view ]['fields'] = array_values( array_merge( $missing, $stored[ $view ]['fields'] ) );
			update_user_meta( (int) $user_id, self::META, $stored );
		}
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
