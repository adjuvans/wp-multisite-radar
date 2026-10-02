<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\PluginsQuery;
use MultisiteRadar\Query\SitesQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /plugins et GET /plugins/{plugin}/sites, où {plugin} est le fichier sans « .php » (écart E1 du plan M3).
 */
final class PluginsController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'plugins';

	private PluginsQuery $plugins;
	private SitesQuery $sites;

	public function __construct( PluginsQuery $plugins, SitesQuery $sites ) {
		$this->plugins = $plugins;
		$this->sites   = $sites;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => $this->get_collection_params(),
				],
			]
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<plugin>[^/]+(?:/[^/]+)?)/sites',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_sites' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => self::sites_page_params(),
				],
			]
		);
	}

	public function get_collection_params(): array {
		return self::inventory_params( PluginsQuery::STATUSES );
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$result = $this->plugins->list( self::inventory_args( $request ) );
				return $this->paginated( $result['items'], $result['total'], (int) $request['per_page'] );
			}
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_sites( WP_REST_Request $request ) {
		return $this->guard(
			function () use ( $request ) {
				$plugin = $this->plugins->find( (string) $request['plugin'] );
				if ( null === $plugin ) {
					return new WP_Error( 'msradar_plugin_not_found', __( 'This plugin is neither installed nor active on any site.', 'multisite-radar' ), [ 'status' => 404 ] );
				}
				return $this->sites_page( $this->sites, [ 'plugin' => $plugin['file'] ], $request );
			}
		);
	}
}
