<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Query\SitesQuery;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * multisite-radar/get-site : la fiche complète d'un site du réseau courant.
 */
final class GetSiteAbility extends Ability {

	private SitesQuery $sites;

	public function __construct( SitesQuery $sites ) {
		$this->sites = $sites;
	}

	public function slug(): string {
		return 'get-site';
	}

	public function label(): string {
		return __( 'Get a site', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'Full audit sheet of one site of the network, by its ID: measures, content types and taxonomies with the plugin or theme that registers them, users by role and privileged accounts (logins only), last content, overdue scheduled tasks, alerts with their message, active plugins and theme, and the last analysis error if any.', 'multisite-radar' );
	}

	public function input_schema(): array {
		return self::input(
			[
				'id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Site ID, as returned by multisite-radar/list-sites.', 'multisite-radar' ),
				],
			],
			[ 'id' ]
		);
	}

	public function output_schema(): array {
		return Schemas::site_detail();
	}

	/**
	 * @param array $input Entrée validée par le cœur.
	 * @return array|WP_Error
	 */
	protected function run( array $input ) {
		$site = $this->sites->get( (int) ( $input['id'] ?? 0 ) );
		if ( null === $site ) {
			return new WP_Error( 'msradar_site_not_found', __( 'Site not found.', 'multisite-radar' ), [ 'status' => 404 ] );
		}
		// Des tableaux associatifs vides seraient encodés [] : le client attend des objets, comme avec la route REST.
		$site['options']          = (object) $site['options'];
		$site['users']['by_role'] = (object) ( $site['users']['by_role'] ?? [] );
		return $site;
	}
}
