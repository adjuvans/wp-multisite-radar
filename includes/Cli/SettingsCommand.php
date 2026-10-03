<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Settings\SettingsUpdater;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and changes the settings of Multisite Radar.
 */
final class SettingsCommand extends Command {

	/**
	 * Prints the settings, or one setting by its dotted path.
	 *
	 * Only modified settings are stored, but alerts.rules.<rule> shows the effective configuration of the rule (enabled,
	 * severity, params), defaults included.
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : Dotted path of a setting, e.g. scan.full_rescan_days or alerts.rules.inactive.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp multisite-radar settings get scan.full_rescan_days
	 *     7
	 *
	 *     $ wp multisite-radar settings get --format=yaml
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function get( array $args, array $assoc_args ): void {
		$settings = $this->plugin->settings();
		$value    = $settings->all();
		if ( isset( $args[0] ) ) {
			$missing = new \stdClass();
			$keys    = explode( '.', (string) $args[0] );
			$source  = $settings->all();
			$rule    = 'alerts' === $keys[0] && 'rules' === ( $keys[1] ?? '' ) ? $this->plugin->rules()->get( (string) ( $keys[2] ?? '' ) ) : null;
			if ( null !== $rule ) {
				// Les réglages ne gardent que ce qui a été modifié : la règle se lit dans sa configuration effective.
				$source = $this->plugin->evaluator()->config( $rule );
				$keys   = array_slice( $keys, 3 );
			}
			$value = self::dig( $source, $keys, $missing );
			if ( $missing === $value ) {
				WP_CLI::error( sprintf( 'Unknown setting: %s', (string) $args[0] ) );
				return;
			}
		}

		if ( 'yaml' === ( $assoc_args['format'] ?? 'json' ) ) {
			WP_CLI::print_value( $value, [ 'format' => 'yaml' ] );
			return;
		}
		WP_CLI::line( (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Changes one setting, with the same validation as the settings page.
	 *
	 * The value is read as JSON when it is valid JSON (true, 14, ["post","page"], {"enabled":false}), as text
	 * otherwise. Like the settings page, changing an analysis setting or an alert rule schedules the matching background
	 * work (new analysis, alerts computed again).
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Dotted path of the setting, e.g. scan.full_rescan_days.
	 *
	 * <value>
	 * : New value.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp multisite-radar settings set scan.full_rescan_days 14
	 *     Success: Updated scan.full_rescan_days.
	 *
	 *     $ wp multisite-radar settings set alerts.rules.inactive '{"params":{"months":12}}'
	 *     Success: Updated alerts.rules.inactive.
	 *
	 *     $ wp multisite-radar settings set integrations.mcp_public true
	 *     Success: Updated integrations.mcp_public.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function set( array $args, array $assoc_args ): void {
		$key   = (string) $args[0];
		$patch = SettingsUpdater::patch_for( $key, Values::parse( (string) $args[1] ) );
		if ( null === $patch ) {
			WP_CLI::error( sprintf( 'Invalid setting path: %s', $key ) );
			return;
		}

		$result = $this->plugin->settings_updater()->apply( $patch );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}
		WP_CLI::success( sprintf( 'Updated %s.', $key ) );
	}

	/**
	 * @param array<string, mixed> $source  Arbre de valeurs.
	 * @param string[]             $keys    Chemin à parcourir (vide : tout l'arbre).
	 * @param object               $missing Valeur renvoyée si le chemin n'existe pas.
	 * @return mixed
	 */
	private static function dig( array $source, array $keys, $missing ) {
		$value = $source;
		foreach ( $keys as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $missing;
			}
			$value = $value[ $key ];
		}
		return $value;
	}
}
