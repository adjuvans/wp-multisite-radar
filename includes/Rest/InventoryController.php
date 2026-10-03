<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\InventoryQuery;
use MultisiteRadar\Query\Schemas;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /inventory/summary : compteurs des plugins et des thèmes, sites encore à analyser (écart E2 du plan M3).
 */
final class InventoryController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'inventory';

	private InventoryQuery $inventory;

	public function __construct( InventoryQuery $inventory ) {
		$this->inventory = $inventory;
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
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary() {
		return $this->guard(
			function (): WP_REST_Response {
				return new WP_REST_Response( $this->inventory->summary() );
			}
		);
	}

	public function get_item_schema(): array {
		return Schemas::for_rest( 'msradar-inventory-summary', Schemas::inventory_summary() );
	}
}
