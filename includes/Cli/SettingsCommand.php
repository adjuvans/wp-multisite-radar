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
			$value   = $settings->get( (string) $args[0], $missing );
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
}
