<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\Schemas;
use MultisiteRadar\Query\UsersQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /users : comptes de l'installation, nombre de sites, rôles, noms, contenus publiés ; GET /users/{id} : fiche
 * d'un compte. L'e-mail n'est renvoyé qu'à un compte qui a le droit manage_network_users (écart E1 du plan rc.2).
 */
final class UsersController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'users';

	private UsersQuery $users;

	public function __construct( UsersQuery $users ) {
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
				'schema' => [ $this, 'get_detail_schema' ],
			]
		);
	}

	public function get_collection_params(): array {
		return array_merge(
			self::sites_page_params(),
			[
				'membership'  => [
					'type'    => 'string',
					'default' => '',
					'enum'    => array_merge( [ '' ], UsersQuery::MEMBERSHIPS ),
				],
				'super_admin' => [
					'type'    => 'boolean',
					'default' => false,
				],
				'orderby'     => [
					'type'    => 'string',
					'default' => 'login',
					'enum'    => UsersQuery::ORDERBY,
				],
				'order'       => [
					'type'    => 'string',
					'default' => 'asc',
					'enum'    => [ 'asc', 'desc' ],
				],
			]
		);
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->guard(
			function () use ( $request ): WP_REST_Response {
				$per_page = (int) $request['per_page'];
				$result   = $this->users->list(
					[
						'page'        => (int) $request['page'],
						'per_page'    => $per_page,
						'search'      => (string) $request['search'],
						'membership'  => (string) $request['membership'],
						'super_admin' => (bool) $request['super_admin'],
						'orderby'     => (string) $request['orderby'],
						'order'       => (string) $request['order'],
						'with_email'  => self::can_see_emails(),
					]
				);
				return $this->paginated( $result['items'], $result['total'], $per_page );
			}
		);
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		return $this->guard(
			function () use ( $request ) {
				$item = $this->users->get( (int) $request['id'], self::can_see_emails() );
				if ( null === $item ) {
					return new WP_Error( 'msradar_user_not_found', __( 'Account not found.', 'multisite-radar' ), [ 'status' => 404 ] );
				}
				return new WP_REST_Response( $item );
			}
		);
	}

	public function get_item_schema(): array {
		return Schemas::for_rest( 'msradar-user', Schemas::user() );
	}

	public function get_detail_schema(): array {
		return Schemas::for_rest( 'msradar-user-detail', Schemas::user_detail() );
	}

	/**
	 * Le droit de WordPress qui montre déjà les e-mails dans Réseau > Utilisateurs.
	 */
	private static function can_see_emails(): bool {
		return current_user_can( 'manage_network_users' );
	}
}
