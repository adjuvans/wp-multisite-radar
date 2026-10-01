<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Settings\Preferences;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Préférences d'affichage de l'utilisateur courant.
 */
final class PreferencesController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'preferences';

	private Preferences $preferences;

	public function __construct( Preferences $preferences ) {
		$this->preferences = $preferences;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_preferences' ],
					'permission_callback' => [ $this, 'can_view' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'update_preferences' ],
					'permission_callback' => [ $this, 'can_view' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	public function get_item_schema(): array {
		return array_merge(
			[
				'$schema' => 'http://json-schema.org/draft-04/schema#',
				'title'   => 'msradar-preferences',
			],
			Preferences::schema()
		);
	}

	public function get_preferences(): WP_REST_Response {
		return new WP_REST_Response( $this->preferences->get( get_current_user_id() ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_preferences( WP_REST_Request $request ) {
		$patch = (array) $request->get_json_params();
		if ( [] === $patch ) {
			return new WP_Error( 'msradar_invalid_preferences', __( 'Expected a JSON object.', 'multisite-radar' ), [ 'status' => 400 ] );
		}
		$result = $this->preferences->update( get_current_user_id(), $patch );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}
}
