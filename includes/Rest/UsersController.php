<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Query\UsersQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /users : comptes de l'installation, nombre de sites, super-admins, sans adresse e-mail.
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
					]
				);
				return $this->paginated( $result['items'], $result['total'], $per_page );
			}
		);
	}
}
