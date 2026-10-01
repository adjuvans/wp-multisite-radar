<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Query\SiteUsersQuery;
use MultisiteRadar\Query\SitesQuery;
use MultisiteRadar\Storage\SitesRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class SitesController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'sites';

	private SitesQuery $query;

	private SiteUsersQuery $users;

	public function __construct( SitesQuery $query, SiteUsersQuery $users ) {
		$this->query = $query;
		$this->users = $users;
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
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => [
						'id' => [
							'type'    => 'integer',
							'minimum' => 1,
						],
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/users',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_users' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => [
						'id'       => [
							'type'    => 'integer',
							'minimum' => 1,
						],
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
						'role'     => [
							'type'    => 'string',
							'default' => '',
							'pattern' => '^[a-z0-9_-]{0,60}$',
						],
						'orderby'  => [
							'type'    => 'string',
							'default' => 'login',
							'enum'    => SiteUsersQuery::ORDERBY,
						],
						'order'    => [
							'type'    => 'string',
							'default' => 'asc',
							'enum'    => [ 'asc', 'desc' ],
						],
					],
				],
			]
		);
	}

	public function get_collection_params(): array {
		return [
			'page'            => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page'        => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
			'search'          => [
				'type'    => 'string',
				'default' => '',
			],
			'orderby'         => [
				'type'    => 'string',
				'default' => 'name',
				'enum'    => array_keys( SitesRepository::ORDERBY ),
			],
			'order'           => [
				'type'    => 'string',
				'default' => 'asc',
				'enum'    => [ 'asc', 'desc' ],
			],
			'alert_level'     => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type' => 'string',
					'enum' => Severity::names(),
				],
			],
			'status'          => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type' => 'string',
					'enum' => SitesQuery::STATUSES,
				],
			],
			'theme'           => [
				'type'    => 'string',
				'default' => '',
			],
			'plugin'          => [
				'type'    => 'string',
				'default' => '',
			],
			'has_users'       => [ 'type' => 'boolean' ],
			'inactive_since'  => [
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'Sites analysed, with an activity date, and no activity since this date.', 'multisite-radar' ),
			],
			'registry_status' => [
				'type'    => 'array',
				'default' => [],
				'items'   => [
					'type' => 'string',
					'enum' => SitesQuery::REGISTRY_STATUSES,
				],
			],
			'rule'            => [
				'type'    => 'string',
				'default' => '',
			],
			'include'         => [
				'type'     => 'array',
				'default'  => [],
				'maxItems' => SitesQuery::MAX_INCLUDE,
				'items'    => [
					'type'    => 'integer',
					'minimum' => 1,
				],
			],
		];
	}

	/**
	 * @param \WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$per_page = (int) $request['per_page'];
				// Sans forcer l'UTC : un décalage explicite (+02:00) est converti, et non remplacé.
				$since  = isset( $request['inactive_since'] ) ? rest_parse_date( (string) $request['inactive_since'] ) : false;
				$result = $this->query->list(
					[
						'page'            => (int) $request['page'],
						'per_page'        => $per_page,
						'search'          => (string) $request['search'],
						'orderby'         => (string) $request['orderby'],
						'order'           => (string) $request['order'],
						'alert_level'     => (array) $request['alert_level'],
						'status'          => (array) $request['status'],
						'theme'           => (string) $request['theme'],
						'plugin'          => (string) $request['plugin'],
						'has_users'       => isset( $request['has_users'] ) ? (bool) $request['has_users'] : null,
						'inactive_since'  => false !== $since ? gmdate( 'Y-m-d H:i:s', (int) $since ) : null,
						'registry_status' => (array) $request['registry_status'],
						'rule'            => (string) $request['rule'],
						'include'         => (array) $request['include'],
					]
				);

				return $this->paginated( $result['items'], $result['total'], $per_page );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$item = $this->query->get( (int) $request['id'] );
		if ( null === $item ) {
			return new WP_Error( 'msradar_site_not_found', __( 'Site not found.', 'multisite-radar' ), [ 'status' => 404 ] );
		}
		// Des tableaux associatifs vides seraient encodés [] : le client attend des objets.
		$item['options']          = (object) $item['options'];
		$item['users']['by_role'] = (object) ( $item['users']['by_role'] ?? [] );
		return new WP_REST_Response( $item );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_users( WP_REST_Request $request ) {
		$per_page = (int) $request['per_page'];
		$result   = $this->users->list(
			(int) $request['id'],
			[
				'page'     => (int) $request['page'],
				'per_page' => $per_page,
				'search'   => (string) $request['search'],
				'role'     => (string) $request['role'],
				'orderby'  => (string) $request['orderby'],
				'order'    => (string) $request['order'],
			]
		);
		if ( null === $result ) {
			return new WP_Error( 'msradar_site_not_found', __( 'Site not found.', 'multisite-radar' ), [ 'status' => 404 ] );
		}
		return $this->paginated( $result['items'], $result['total'], $per_page );
	}

	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$int          = [
			'type'     => 'integer',
			'readonly' => true,
		];
		$nullable_int = [
			'type'     => [ 'integer', 'null' ],
			'readonly' => true,
		];
		$date         = [
			'type'     => [ 'string', 'null' ],
			'format'   => 'date-time',
			'readonly' => true,
		];

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'msradar-site',
			'type'       => 'object',
			'properties' => [
				'id'                => $int,
				'name'              => [ 'type' => 'string' ],
				'url'               => [
					'type'   => 'string',
					'format' => 'uri',
				],
				'admin_url'         => [
					'type'   => 'string',
					'format' => 'uri',
				],
				'status'            => [ 'type' => 'object' ],
				'theme'             => [ 'type' => 'object' ],
				'users_count'       => $int,
				'admins_count'      => $int,
				'content_count'     => $int,
				'media_count'       => $int,
				'disk_bytes'        => $nullable_int,
				'db_bytes'          => $nullable_int,
				'autoload_bytes'    => $nullable_int,
				'last_activity_gmt' => $date,
				'alert_level'       => [
					'type' => 'string',
					'enum' => Severity::names(),
				],
				'alerts_count'      => $int,
				'alert_rules'       => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'registry_status'   => [
					'type' => 'string',
					'enum' => SitesQuery::REGISTRY_STATUSES,
				],
				'pending'           => [ 'type' => 'boolean' ],
				'dirty'             => [ 'type' => 'boolean' ],
				'scanned_at_gmt'    => $date,
			],
		];
		return $this->add_additional_fields_schema( $this->schema );
	}
}
