<?php
namespace MultisiteRadar\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Texte proposé pour la politique de confidentialité (spec §9).
 */
final class Privacy {

	public function register(): void {
		add_action( 'admin_init', [ $this, 'add_policy_content' ] );
	}

	public function add_policy_content(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Multisite Radar', wp_kses_post( wpautop( self::text(), false ) ) );
		}
	}

	public static function text(): string {
		return __( 'Multisite Radar is an audit tool for network administrators. It collects no data from visitors and makes no external requests. To build its inventory of the network, it keeps in the network database a copy of data that WordPress already holds: for each site, the user IDs and logins of up to 50 administrators and editors, and the number of users per role. This copy is refreshed in the background and deleted when the plugin is uninstalled. When a user account is deleted, the sites it belonged to are analysed again. It also keeps a history of the changes of the network (sites created or deleted, plugins and themes, alerts) and daily figures of each site, for the retention period set in its settings. If the weekly e-mail summary is enabled, the addresses of its recipients are kept in the plugin settings and used only to send it.', 'multisite-radar' );
	}
}
