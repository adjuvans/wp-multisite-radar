<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Query\ThemesQuery;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the themes of the network.
 */
final class ThemesCommand extends Command {

	private const DEFAULT_FIELDS = 'stylesheet,name,version,status,active_count,parent_count,update_version';

	/**
	 * Lists the themes installed on the network, or still active on a site after their removal.
	 *
	 * Status: used (active or parent theme of some sites), unused, or missing (used by a site but no longer installed).
	 * Sites waiting for their first analysis are not counted.
	 *
	 * ## OPTIONS
	 *
	 * [--unused]
	 * : Only installed themes that no site uses, as active or parent theme.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields: id, stylesheet, name, version, installed, parent, allowed_on_network, active_count, parent_count, sites_count, status, update_version.
	 * ---
	 * default: stylesheet,name,version,status,active_count,parent_count,update_version
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Themes that could be deleted.
	 *     $ wp multisite-radar themes list --unused
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$filter = get_flag_value( $assoc_args, 'unused', false ) ? [ 'status' => [ ThemesQuery::STATUS_UNUSED ] ] : [];
		try {
			$items = $this->plugin->themes_query()->filtered( $filter );
		} catch ( \RuntimeException $error ) {
			self::read_failed( __METHOD__, $error );
			return;
		}

		format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, (string) ( $assoc_args['fields'] ?? self::DEFAULT_FIELDS ) );
	}
}
