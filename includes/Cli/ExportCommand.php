<?php
namespace MultisiteRadar\Cli;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Exports the sites, plugins or themes of the network as CSV or JSON.
 */
final class ExportCommand extends Command {

	/**
	 * Exports the sites, plugins or themes of the network, like the export buttons of the admin pages.
	 *
	 * Any filter of the matching REST route can be given as an option, e.g. --alert_level=error,warning or
	 * --search=blog for sites, --status=unused for plugins and themes. Lists are comma-separated.
	 * Without --output, the file is written to the standard output and nothing else is printed.
	 *
	 * ## OPTIONS
	 *
	 * --resource=<resource>
	 * : Data to export.
	 * ---
	 * options:
	 *   - sites
	 *   - plugins
	 *   - themes
	 * ---
	 *
	 * [--format=<format>]
	 * : File format.
	 * ---
	 * default: csv
	 * options:
	 *   - csv
	 *   - json
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of columns. Default: every column.
	 *
	 * [--output=<file>]
	 * : Write to this file instead of the standard output. An existing file is replaced.
	 *
	 * [--alert_level=<levels>]
	 * : For sites: filter by alert level (error, warning, info).
	 *
	 * [--search=<term>]
	 * : For sites: filter by search term.
	 *
	 * [--include=<ids>]
	 * : For sites: filter by site IDs.
	 *
	 * [--status=<status>]
	 * : For plugins and themes: filter by status.
	 *
	 * [--order=<order>]
	 * : For sites: sort order (asc or desc).
	 *
	 * ## EXAMPLES
	 *
	 *     # Sites with errors or warnings, as JSON.
	 *     $ wp multisite-radar export --resource=sites --format=json --alert_level=error,warning --output=sites.json
	 *     Success: 12 item(s) exported to sites.json.
	 *
	 *     # Unused plugins, as CSV on the standard output.
	 *     $ wp multisite-radar export --resource=plugins --status=unused > unused-plugins.csv
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$handler = $this->plugin->export();
		$params  = $handler->params( $assoc_args );
		if ( is_wp_error( $params ) ) {
			WP_CLI::error( $params->get_error_message() );
			return;
		}

		$output = (string) ( $assoc_args['output'] ?? '' );
		if ( '' !== $output && ! is_dir( dirname( $output ) ) ) {
			WP_CLI::error( sprintf( 'The folder of %s does not exist.', $output ) );
			return;
		}

		try {
			$handler->check( $params );
		} catch ( \RuntimeException $error ) {
			self::read_failed( __METHOD__, $error );
			return;
		}

		$stream = fopen( '' !== $output ? $output : 'php://stdout', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed export.
		if ( false === $stream ) {
			WP_CLI::error( sprintf( 'Could not write to %s.', $output ) );
			return;
		}
		try {
			$count = $handler->write( $params, $stream );
		} catch ( \RuntimeException $error ) {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streamed export.
			do_action( 'msradar_error', __METHOD__, $error );
			WP_CLI::error( 'The export is incomplete: the data could not be read to the end. Run the export again.' );
			return;
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streamed export.

		if ( '' !== $output ) {
			WP_CLI::success( sprintf( '%d item(s) exported to %s.', $count, $output ) );
		}
	}
}
