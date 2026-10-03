<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Settings\SettingsUpdater;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class SettingsController extends Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'settings';

	private Settings $settings;
	private SettingsUpdater $updater;

	public function __construct( Settings $settings, SettingsUpdater $updater ) {
		$this->settings = $settings;
		$this->updater  = $updater;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	public function get_item_schema(): array {
		return array_merge(
			[
				'$schema' => 'http://json-schema.org/draft-04/schema#',
				'title'   => 'msradar-settings',
			],
			Settings::schema()
		);
	}

	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings->all() );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$patch = (array) $request->get_json_params();
		if ( [] === $patch ) {
			return new WP_Error( 'msradar_invalid_settings', __( 'Expected a JSON object.', 'multisite-radar' ), [ 'status' => 400 ] );
		}

		$result = $this->updater->apply( $patch );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}
}
