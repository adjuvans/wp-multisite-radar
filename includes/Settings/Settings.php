<?php
namespace MultisiteRadar\Settings;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Réglages réseau. Seules les valeurs modifiées sont stockées ; les défauts sont fusionnés à la lecture.
 */
final class Settings {

	public const OPTION = 'msradar_settings';

	private ?array $cache = null;

	public static function defaults(): array {
		$defaults = [
			'scan'         => [
				'activity_post_types' => [ 'post', 'page' ],
				'analysis_plugins'    => [],
				'measure_disk'        => true,
				'full_rescan_days'    => 7,
			],
			'alerts'       => [ 'rules' => [] ],
			'reports'      => [
				'digest_enabled'    => false,
				'digest_day'        => 1,
				'digest_recipients' => [
					'mode'   => 'super_admins',
					'emails' => [],
				],
			],
			'integrations' => [ 'mcp_public' => false ],
			'sites_menu'   => [ 'enabled' => false ],
			'retention'    => [
				'events_days'    => 90,
				'snapshots_days' => 365,
			],
		];

		return (array) apply_filters( 'msradar_default_settings', $defaults );
	}

	public static function schema(): array {
		$section = static function ( array $properties ): array {
			return [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => $properties,
			];
		};
		$days    = static function ( int $maximum ): array {
			return [
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => $maximum,
			];
		};

		return $section(
			[
				'scan'         => $section(
					[
						'activity_post_types' => [
							'type'        => 'array',
							'minItems'    => 1,
							'uniqueItems' => true,
							'items'       => [
								'type'    => 'string',
								'pattern' => '^[a-z0-9_-]{1,20}$',
							],
						],
						'analysis_plugins'    => [
							'type'        => 'array',
							'uniqueItems' => true,
							'items'       => [
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 191,
							],
						],
						'measure_disk'        => [ 'type' => 'boolean' ],
						'full_rescan_days'    => $days( 90 ),
					]
				),
				'alerts'       => $section(
					[
						'rules' => [
							'type'                 => 'object',
							'additionalProperties' => $section(
								[
									'enabled'  => [ 'type' => 'boolean' ],
									'severity' => [
										'type' => [ 'string', 'null' ],
										'enum' => [ 'error', 'warning', 'info', null ],
									],
									'params'   => [ 'type' => 'object' ],
								]
							),
						],
					]
				),
				'reports'      => $section(
					[
						'digest_enabled'    => [ 'type' => 'boolean' ],
						'digest_day'        => [
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 6,
						],
						'digest_recipients' => $section(
							[
								'mode'   => [
									'type' => 'string',
									'enum' => [ 'super_admins', 'custom' ],
								],
								'emails' => [
									'type'  => 'array',
									'items' => [
										'type'   => 'string',
										'format' => 'email',
									],
								],
							]
						),
					]
				),
				'integrations' => $section( [ 'mcp_public' => [ 'type' => 'boolean' ] ] ),
				'sites_menu'   => $section( [ 'enabled' => [ 'type' => 'boolean' ] ] ),
				'retention'    => $section(
					[
						'events_days'    => $days( 3650 ),
						'snapshots_days' => $days( 3650 ),
					]
				),
			]
		);
	}

	public function all(): array {
		if ( null === $this->cache ) {
			$this->cache = self::merge( self::defaults(), $this->stored() );
		}
		return $this->cache;
	}

	/**
	 * @param string $path     Chemin pointé, ex. « scan.activity_post_types ».
	 * @param mixed  $fallback Valeur renvoyée si le chemin n'existe pas.
	 * @return mixed
	 */
	public function get( string $path, $fallback = null ) {
		$value = $this->all();
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $fallback;
			}
			$value = $value[ $key ];
		}
		return $value;
	}

	/**
	 * @return array|WP_Error Réglages complets après mise à jour, ou erreur de validation (statut 400).
	 */
	public function update( array $patch ) {
		$schema = self::schema();
		$valid  = rest_validate_value_from_schema( $patch, $schema, 'settings' );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'msradar_invalid_settings', $valid->get_error_message(), [ 'status' => 400 ] );
		}

		$clean = rest_sanitize_value_from_schema( $patch, $schema, 'settings' );
		if ( ! is_array( $clean ) ) {
			return new WP_Error( 'msradar_invalid_settings', __( 'Invalid settings.', 'multisite-radar' ), [ 'status' => 400 ] );
		}

		$old = $this->all();
		update_site_option( self::OPTION, self::merge( $this->stored(), $clean ) );
		$this->cache = null;
		$new         = $this->all();

		do_action( 'msradar_settings_updated', $new, $old );

		return $new;
	}

	public function rule_config( string $rule_id ): array {
		$rules = $this->get( 'alerts.rules', [] );
		return is_array( $rules ) && isset( $rules[ $rule_id ] ) && is_array( $rules[ $rule_id ] ) ? $rules[ $rule_id ] : [];
	}

	public function reset_cache(): void {
		$this->cache = null;
	}

	/**
	 * Fusion récursive : les tableaux associatifs sont fusionnés, les listes remplacées.
	 */
	public static function merge( array $base, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] )
				&& ! self::is_list( $value ) && ! self::is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
				continue;
			}
			$base[ $key ] = $value;
		}
		return $base;
	}

	private function stored(): array {
		$stored = get_site_option( self::OPTION, [] );
		return is_array( $stored ) ? $stored : [];
	}

	private static function is_list( array $value ): bool {
		return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
