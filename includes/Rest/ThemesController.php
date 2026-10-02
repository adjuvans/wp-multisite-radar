<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Query\ThemesQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /themes et GET /themes/{stylesheet}/sites (sites où le thème est actif ou parent du thème actif).
 */
final class ThemesController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'themes';

	private ThemesQuery $themes;
	private SitesQuery $sites;

	public function __construct( ThemesQuery $themes, SitesQuery $sites ) {
		$this->themes = $themes;
		$this->sites  = $sites;
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
		// Même motif que la route wp/v2/themes du cœur : un dossier, éventuellement dans un sous-dossier.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<stylesheet>[^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)/sites',
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
		return self::inventory_params( ThemesQuery::STATUSES );
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$result = $this->themes->list( self::inventory_args( $request ) );
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
				$theme = $this->themes->find( (string) $request['stylesheet'] );
				if ( null === $theme ) {
					return new WP_Error( 'msradar_theme_not_found', __( 'This theme is neither installed nor used by any site.', 'multisite-radar' ), [ 'status' => 404 ] );
				}
				return $this->sites_page( $this->sites, [ 'theme' => $theme['stylesheet'] ], $request );
			}
		);
	}
}
