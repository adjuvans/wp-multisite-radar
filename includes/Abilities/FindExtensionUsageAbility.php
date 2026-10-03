<?php
namespace MultisiteRadar\Abilities;

use MultisiteRadar\Query\PluginsQuery;
use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Query\ThemesQuery;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * multisite-radar/find-extension-usage : un plugin ou un thème, et une page des sites qui l'utilisent (écart E4).
 */
final class FindExtensionUsageAbility extends Ability {

	private PluginsQuery $plugins;
	private ThemesQuery $themes;
	private SitesQuery $sites;

	public function __construct( PluginsQuery $plugins, ThemesQuery $themes, SitesQuery $sites ) {
		$this->plugins = $plugins;
		$this->themes  = $themes;
		$this->sites   = $sites;
	}

	public function slug(): string {
		return 'find-extension-usage';
	}

	public function label(): string {
		return __( 'Find where a plugin or theme is used', 'multisite-radar' );
	}

	public function description(): string {
		return __( 'Finds the sites of the network that use a plugin (active on the site, or network-activated) or a theme (as active or parent theme), with the plugin or theme details: version, status and available update. Identify a plugin by its file, e.g. akismet/akismet.php, and a theme by its folder, e.g. twentytwentyfive.', 'multisite-radar' );
	}

	public function input_schema(): array {
		return self::input(
			array_merge(
				[
					'type' => [
						'type'        => 'string',
						'enum'        => [ 'plugin', 'theme' ],
						'description' => __( 'Kind of extension.', 'multisite-radar' ),
					],
					'id'   => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Plugin file, with or without ".php", or theme folder.', 'multisite-radar' ),
					],
				],
				self::paging()
			),
			[ 'type', 'id' ]
		);
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'extension' => [ 'anyOf' => [ Schemas::plugin(), Schemas::theme() ] ],
				'sites'     => Schemas::page_of( Schemas::site() ),
			],
		];
	}

	/**
	 * @param array $input Entrée validée par le cœur.
	 * @return array|WP_Error
	 */
	protected function run( array $input ) {
		$id = (string) ( $input['id'] ?? '' );
		if ( 'theme' === ( $input['type'] ?? '' ) ) {
			$extension = $this->themes->find( $id );
			$filter    = [ 'theme' => $id ];
		} else {
			$extension = $this->plugins->find( PluginsQuery::id( $id ) );
			$filter    = [ 'plugin' => null !== $extension ? $extension['file'] : '' ];
		}
		if ( null === $extension ) {
			return new WP_Error( 'msradar_extension_not_found', __( 'This plugin or theme is neither installed nor used on any site of the network.', 'multisite-radar' ), [ 'status' => 404 ] );
		}

		$page     = self::page_number( $input );
		$per_page = self::page_size( $input );
		$result   = $this->sites->list(
			array_merge(
				$filter,
				[
					'page'     => $page,
					'per_page' => $per_page,
					'orderby'  => 'name',
					'order'    => 'asc',
				]
			)
		);
		return [
			'extension' => $extension,
			'sites'     => self::page( $result, $page, $per_page ),
		];
	}
}
