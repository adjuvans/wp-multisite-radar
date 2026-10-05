<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Query\TrendsQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /reports/trends : séries du réseau ou d'un site (spec §5.1, écart E9).
 */
final class ReportsController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'reports';

	private TrendsQuery $trends;

	public function __construct( TrendsQuery $trends ) {
		$this->trends = $trends;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/trends',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_trends' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => [
						'days' => [
							'type'    => 'integer',
							'default' => TrendsQuery::DEFAULT_DAYS,
							'minimum' => 2,
							'maximum' => TrendsQuery::MAX_DAYS,
						],
						'site' => [
							'type'    => 'integer',
							'minimum' => 1,
						],
					],
				],
				'schema' => [ $this, 'get_trends_schema' ],
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_trends( WP_REST_Request $request ) {
		return $this->guard(
			function () use ( $request ) {
				$days = (int) $request['days'];
				if ( isset( $request['site'] ) ) {
					$trends = $this->trends->site( (int) $request['site'], $days );
					if ( null === $trends ) {
						return new WP_Error( 'msradar_site_not_found', __( 'Site not found.', 'multisite-radar' ), [ 'status' => 404 ] );
					}
					return new WP_REST_Response( $trends );
				}
				return new WP_REST_Response( $this->trends->network( $days ) );
			}
		);
	}

	public function get_trends_schema(): array {
		return Schemas::for_rest( 'msradar-trends', Schemas::trends() );
	}
}
