<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\EventsQuery;
use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Storage\EventsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /events : le journal des changements du réseau courant, les plus récents d'abord (spec §5.1).
 */
final class EventsController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'events';

	private EventsQuery $events;

	public function __construct( EventsQuery $events ) {
		$this->events = $events;
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
				'schema' => [ $this, 'get_public_item_schema' ],
			]
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
			'since'    => [
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'Only changes since this date.', 'multisite-radar' ),
			],
			'type'     => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type' => 'string',
					'enum' => EventsRepository::TYPES,
				],
			],
			'site'     => [
				'type'    => 'integer',
				'minimum' => 1,
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
				// Sans forcer l'UTC : un décalage explicite (+02:00) est converti, et non remplacé.
				$since  = isset( $request['since'] ) ? rest_parse_date( (string) $request['since'] ) : false;
				$result = $this->events->list(
					[
						'page'     => (int) $request['page'],
						'per_page' => $per_page,
						'since'    => false !== $since ? gmdate( 'Y-m-d H:i:s', (int) $since ) : null,
						'type'     => (array) $request['type'],
						'site'     => isset( $request['site'] ) ? (int) $request['site'] : 0,
					]
				);
				return $this->paginated( $result['items'], $result['total'], $per_page );
			}
		);
	}

	public function get_item_schema(): array {
		return Schemas::for_rest( 'msradar-event', Schemas::event() );
	}
}
