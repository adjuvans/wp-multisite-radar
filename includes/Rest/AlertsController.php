<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Query\AlertsQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class AlertsController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'alerts';

	private AlertsQuery $query;

	public function __construct( AlertsQuery $query ) {
		$this->query = $query;
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
			'/' . $this->rest_base . '/summary',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_summary' ],
					'permission_callback' => [ $this, 'can_view' ],
				],
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary() {
		return $this->guard(
			function (): WP_REST_Response {
				return new WP_REST_Response( $this->query->summary( get_current_network_id() ) );
			}
		);
	}

	public function get_collection_params(): array {
		return [
			'page'     => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page' => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
			'search'   => [
				'type'    => 'string',
				'default' => '',
			],
			'severity' => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type' => 'string',
					'enum' => [ 'error', 'warning', 'info' ],
				],
			],
			'rule'     => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type'    => 'string',
					'pattern' => trim( RuleRegistry::ID_PATTERN, '/' ),
				],
			],
			'orderby'  => [
				'type'    => 'string',
				'default' => 'rule',
				'enum'    => AlertsQuery::ORDERBY,
			],
			'order'    => [
				'type'    => 'string',
				'default' => 'asc',
				'enum'    => [ 'asc', 'desc' ],
			],
		];
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$per_page = (int) $request['per_page'];
				$result   = $this->query->list(
					[
						'page'     => (int) $request['page'],
						'per_page' => $per_page,
						'search'   => (string) $request['search'],
						'severity' => (array) $request['severity'],
						'rule'     => (array) $request['rule'],
						'orderby'  => (string) $request['orderby'],
						'order'    => (string) $request['order'],
					]
				);
				return $this->paginated( $result['items'], $result['total'], $per_page );
			}
		);
	}
}
