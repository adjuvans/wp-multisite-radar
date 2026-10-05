<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\Schemas;
use MultisiteRadar\SitesMenu\Module;
use MultisiteRadar\SitesMenu\Renderer;
use MultisiteRadar\SitesMenu\SitesListCache;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /sites-menu/sites : sites proposés dans les réglages du bloc « Network sites » (écart E4 du plan M7).
 * Lu par l'éditeur de blocs de n'importe quel site du réseau : droit edit_posts sur ce site. Seulement les sites
 * publics, déjà visibles de tous dans le menu, avec leur identifiant et leur nom.
 */
final class SitesMenuController extends Controller {

	public const MAX_PER_PAGE = 50;

	/**
	 * @var string
	 */
	protected $rest_base = 'sites-menu/sites';

	private Module $module;
	private SitesListCache $cache;

	public function __construct( Module $module, SitesListCache $cache ) {
		$this->module = $module;
		$this->cache  = $cache;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'can_edit_posts' ],
					'args'                => $this->get_collection_params(),
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	public function can_edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}

	public function get_collection_params(): array {
		return [
			'search'   => [
				'type'    => 'string',
				'default' => '',
			],
			'include'  => [
				'type'     => 'array',
				'default'  => [],
				'maxItems' => 100,
				'items'    => [ 'type' => 'integer' ],
			],
			'per_page' => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => self::MAX_PER_PAGE,
			],
		];
	}

	/**
	 * Avec include : ces sites (ceux qui sont encore publics), pour nommer les jetons déjà choisis. Sinon : les sites
	 * dont le nom contient la recherche (sans casse ni accents) ou dont l'identifiant vaut la recherche (« 12 », « #12 »).
	 * Triés par nom, per_page au plus.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		if ( ! $this->module->enabled() ) {
			return new WP_Error( 'msradar_sites_menu_disabled', __( 'The network sites menu is disabled.', 'multisite-radar' ), [ 'status' => 404 ] );
		}
		$include = array_map( 'intval', (array) $request['include'] );
		$sites   = Renderer::select( $this->cache->get(), $include, [], 'name', 'asc' );
		$search  = trim( (string) $request['search'] );
		if ( [] === $include && '' !== $search ) {
			$needle = Renderer::sort_key( $search );
			$id     = ltrim( $search, '#' );
			$sites  = array_filter(
				$sites,
				static fn ( array $site ): bool => false !== strpos( Renderer::sort_key( Renderer::label( $site ) ), $needle ) || (string) $site['id'] === $id
			);
		}
		$limit = [] !== $include ? count( $include ) : (int) $request['per_page'];
		return new WP_REST_Response(
			array_map(
				static fn ( array $site ): array => [
					'id'   => (int) $site['id'],
					'name' => Renderer::label( $site ),
				],
				array_slice( array_values( $sites ), 0, $limit )
			)
		);
	}

	public function get_item_schema(): array {
		return Schemas::for_rest(
			'msradar-menu-site',
			[
				'type'       => 'object',
				'properties' => [
					'id'   => [
						'type'     => 'integer',
						'readonly' => true,
					],
					'name' => [
						'type'     => 'string',
						'readonly' => true,
					],
				],
			]
		);
	}
}
