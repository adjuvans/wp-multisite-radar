<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\EventsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Formes JSON des résultats des services Query, partagées par les routes REST (get_item_schema) et les abilities
 * (output_schema). Le cœur valide la sortie d'une ability avec ce schéma : chaque clé renvoyée y est décrite, et rien
 * n'y est plus strict que les données (mesures et dates nulles, adresse d'administration vide).
 */
final class Schemas {

	/**
	 * Un site dans une liste (SitesQuery::summary()).
	 */
	public static function site(): array {
		return self::object(
			array_merge(
				self::identity(),
				[
					'status'            => self::object(
						[
							'public'   => self::type( 'boolean' ),
							'archived' => self::type( 'boolean' ),
							'spam'     => self::type( 'boolean' ),
							'deleted'  => self::type( 'boolean' ),
						]
					),
					'theme'             => self::object(
						[
							'stylesheet' => self::type( 'string' ),
							'template'   => self::type( 'string' ),
						]
					),
					'users_count'       => self::type( 'integer' ),
					'admins_count'      => self::type( 'integer' ),
					'content_count'     => self::type( 'integer' ),
					'media_count'       => self::type( 'integer' ),
					'disk_bytes'        => self::type( [ 'integer', 'null' ] ),
					'disk_is_estimate'  => self::type( 'boolean' ),
					'db_bytes'          => self::type( [ 'integer', 'null' ] ),
					'autoload_bytes'    => self::type( [ 'integer', 'null' ] ),
					'last_activity_gmt' => self::date(),
					'alert_level'       => self::enum( Severity::names() ),
					'alerts_count'      => self::type( 'integer' ),
					'alert_rules'       => self::list_of( self::type( 'string' ) ),
					'registry_status'   => self::enum( SitesQuery::REGISTRY_STATUSES ),
					'pending'           => self::type( 'boolean' ),
					'dirty'             => self::type( 'boolean' ),
					'scanned_at_gmt'    => self::date(),
				]
			)
		);
	}

	/**
	 * La fiche d'un site (SitesQuery::get()). Les objets imbriqués venus de la collecte restent ouverts.
	 */
	public static function site_detail(): array {
		$schema                = self::site();
		$schema['properties'] += [
			'post_types'   => self::list_of( self::type( 'object' ) ),
			'taxonomies'   => self::list_of( self::type( 'object' ) ),
			'users'        => self::type( 'object' ),
			'last_content' => self::type( [ 'object', 'null' ] ),
			'options'      => self::type( 'object' ),
			'cron'         => self::type( [ 'object', 'null' ] ),
			'alerts'       => self::list_of(
				self::object(
					[
						'rule'     => self::type( 'string' ),
						'severity' => self::type( 'string' ),
						'label'    => self::type( 'string' ),
						'message'  => self::type( 'string' ),
					]
				)
			),
			'extensions'   => self::type( 'object' ),
			'scan_error'   => self::type( [ 'object', 'null' ] ),
		];
		return $schema;
	}

	/**
	 * Un compte d'un site (SiteUsersQuery::list()). Jamais d'adresse e-mail.
	 */
	public static function site_user(): array {
		return self::object(
			[
				'id'             => self::type( 'integer' ),
				'login'          => self::type( 'string' ),
				'display_name'   => self::type( 'string' ),
				'roles'          => self::list_of( self::type( 'string' ) ),
				'role_names'     => self::list_of( self::type( 'string' ) ),
				'super_admin'    => self::type( 'boolean' ),
				'registered_gmt' => self::date(),
			]
		);
	}

	/**
	 * Un plugin de l'inventaire (PluginsQuery::all()).
	 */
	public static function plugin(): array {
		return self::object(
			[
				'id'             => self::type( 'string' ),
				'file'           => self::type( 'string' ),
				'name'           => self::type( 'string' ),
				'version'        => self::type( 'string' ),
				'installed'      => self::type( 'boolean' ),
				'network_active' => self::type( 'boolean' ),
				'sites_count'    => self::type( 'integer' ),
				'status'         => self::enum( PluginsQuery::STATUSES ),
				'update_version' => self::type( [ 'string', 'null' ] ),
			]
		);
	}

	/**
	 * Un thème de l'inventaire (ThemesQuery::all()).
	 */
	public static function theme(): array {
		return self::object(
			[
				'id'                 => self::type( 'string' ),
				'stylesheet'         => self::type( 'string' ),
				'name'               => self::type( 'string' ),
				'version'            => self::type( 'string' ),
				'installed'          => self::type( 'boolean' ),
				'parent'             => self::type( [ 'string', 'null' ] ),
				'allowed_on_network' => self::type( 'boolean' ),
				'active_count'       => self::type( 'integer' ),
				'parent_count'       => self::type( 'integer' ),
				'sites_count'        => self::type( 'integer' ),
				'status'             => self::enum( ThemesQuery::STATUSES ),
				'update_version'     => self::type( [ 'string', 'null' ] ),
			]
		);
	}

	/**
	 * Un compte du réseau (UsersQuery::list()). Jamais d'adresse e-mail.
	 */
	public static function user(): array {
		return self::object(
			[
				'id'             => self::type( 'integer' ),
				'login'          => self::type( 'string' ),
				'display_name'   => self::type( 'string' ),
				'super_admin'    => self::type( 'boolean' ),
				'sites_count'    => self::type( 'integer' ),
				'registered_gmt' => self::date(),
				'edit_url'       => self::type( 'string' ),
			]
		);
	}

	/**
	 * Une paire site × règle (AlertsQuery::list()).
	 */
	public static function alert(): array {
		return self::object(
			[
				'id'       => self::type( 'string' ),
				'site'     => self::object( self::identity() ),
				'rule'     => self::type( 'string' ),
				'label'    => self::type( 'string' ),
				'severity' => self::enum( [ Severity::ERROR, Severity::WARNING, Severity::INFO ] ),
				'message'  => self::type( 'string' ),
			]
		);
	}

	/**
	 * Un événement du journal (EventsQuery::format()). Le site vaut null pour un événement du réseau entier.
	 */
	public static function event(): array {
		return self::object(
			[
				'id'          => self::type( 'integer' ),
				'type'        => self::enum( EventsRepository::TYPES ),
				'site'        => [
					'type'       => [ 'object', 'null' ],
					'properties' => self::identity(),
				],
				'subject'     => self::type( 'string' ),
				'label'       => self::type( 'string' ),
				'message'     => self::type( 'string' ),
				'created_gmt' => self::date(),
			]
		);
	}

	/**
	 * Synthèse des alertes (AlertsQuery::summary()).
	 */
	public static function alerts_summary(): array {
		return self::object(
			[
				'total_sites'       => self::type( 'integer' ),
				'scanned_sites'     => self::type( 'integer' ),
				'pending_sites'     => self::type( 'integer' ),
				'sites_with_alerts' => self::type( 'integer' ),
				'by_severity'       => self::counts( [ Severity::ERROR, Severity::WARNING, Severity::INFO ] ),
				'by_rule'           => self::list_of(
					self::object(
						[
							'rule'     => self::type( 'string' ),
							'label'    => self::type( 'string' ),
							'severity' => self::type( 'string' ),
							'enabled'  => self::type( 'boolean' ),
							'count'    => self::type( 'integer' ),
						]
					)
				),
			]
		);
	}

	/**
	 * Synthèse de l'inventaire (InventoryQuery::summary()).
	 */
	public static function inventory_summary(): array {
		return self::object(
			[
				'pending_sites' => self::type( 'integer' ),
				'networks'      => self::type( 'integer' ),
				'plugins'       => self::counts( [ 'installed', 'network', 'unused', 'missing', 'updates' ] ),
				'themes'        => self::counts( [ 'installed', 'unused', 'missing', 'updates' ] ),
			]
		);
	}

	/**
	 * État de l'analyse (ScanStatusQuery::status()).
	 */
	public static function scan_status(): array {
		return self::object(
			[
				'total'              => self::type( 'integer' ),
				'remaining'          => self::type( 'integer' ),
				'pending'            => self::type( 'integer' ),
				'locked'             => self::type( 'boolean' ),
				'last_full_scan_gmt' => self::date(),
				'next_run_gmt'       => self::date(),
			]
		);
	}

	/**
	 * Séries de TrendsQuery : points du réseau, ou d'un site si « site » n'est pas null.
	 */
	public static function trends(): array {
		$count = self::type( 'integer' );
		return self::object(
			[
				'days'   => $count,
				'since'  => self::type( 'string' ),
				'site'   => self::type( [ 'integer', 'null' ] ),
				'points' => self::list_of(
					[
						'anyOf' => [
							self::object(
								[
									'day'            => self::type( 'string' ),
									'sites'          => $count,
									'content_count'  => $count,
									'media_count'    => $count,
									'alerts_error'   => $count,
									'alerts_warning' => $count,
									'alerts_info'    => $count,
								]
							),
							self::object(
								[
									'day'           => self::type( 'string' ),
									'users_count'   => $count,
									'content_count' => $count,
									'media_count'   => $count,
									'disk_bytes'    => self::type( [ 'integer', 'null' ] ),
									'db_bytes'      => self::type( [ 'integer', 'null' ] ),
									'alert_level'   => self::enum( Severity::names() ),
									'alerts_count'  => $count,
								]
							),
						],
					]
				),
			]
		);
	}

	/**
	 * Une page d'une liste, telle que la renvoient les abilities (en REST, la pagination passe par les en-têtes).
	 */
	public static function page_of( array $item ): array {
		return self::object(
			[
				'items'       => self::list_of( $item ),
				'total'       => self::type( 'integer' ),
				'page'        => self::type( 'integer' ),
				'per_page'    => self::type( 'integer' ),
				'total_pages' => self::type( 'integer' ),
			]
		);
	}

	/**
	 * Schéma d'élément d'une route REST : la version de JSON Schema et un titre en plus.
	 */
	public static function for_rest( string $title, array $schema ): array {
		return array_merge(
			[
				'$schema' => 'http://json-schema.org/draft-04/schema#',
				'title'   => $title,
			],
			$schema
		);
	}

	/**
	 * Identité d'un site (SitesQuery::identity()). Une adresse d'administration peut être vide : pas de format « uri ».
	 */
	private static function identity(): array {
		return [
			'id'        => self::type( 'integer' ),
			'name'      => self::type( 'string' ),
			'url'       => self::type( 'string' ),
			'admin_url' => self::type( 'string' ),
		];
	}

	/**
	 * @param string[] $keys Compteurs entiers.
	 */
	private static function counts( array $keys ): array {
		return self::object( array_fill_keys( $keys, self::type( 'integer' ) ) );
	}

	private static function object( array $properties ): array {
		return [
			'type'       => 'object',
			'properties' => $properties,
		];
	}

	private static function list_of( array $items ): array {
		return [
			'type'  => 'array',
			'items' => $items,
		];
	}

	/**
	 * @param string|string[] $type Type JSON, ou liste de types (dont « null »).
	 */
	private static function type( $type ): array {
		return [ 'type' => $type ];
	}

	/**
	 * @param string[] $values Valeurs possibles.
	 */
	private static function enum( array $values ): array {
		return [
			'type' => 'string',
			'enum' => array_values( $values ),
		];
	}

	/**
	 * Date UTC sans décalage (Y-m-d\TH:i:s), ou null. Pas de « format » : date-time (RFC 3339) exige un décalage, que
	 * ces valeurs n'ont pas, et un validateur strict les rejetterait.
	 */
	private static function date(): array {
		return self::type( [ 'string', 'null' ] );
	}
}
