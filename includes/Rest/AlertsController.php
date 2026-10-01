<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\AlertsQuery;
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

	public function get_summary(): WP_REST_Response {
		return new WP_REST_Response( $this->query->summary( get_current_network_id() ) );
	}
}
