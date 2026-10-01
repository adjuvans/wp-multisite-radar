<?php
namespace MultisiteRadar\Rest;

use MultisiteRadar\Capabilities;
use WP_REST_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Base des contrôleurs : espace de noms et contrôles de capacités.
 */
abstract class Controller extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'multisite-radar/v1';

	public function can_view(): bool {
		return current_user_can( Capabilities::VIEW );
	}

	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE );
	}
}
