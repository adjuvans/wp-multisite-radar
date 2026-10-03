<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Query\PluginsQuery;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the plugins of the network.
 */
final class PluginsCommand extends Command {

	private const DEFAULT_FIELDS = 'file,name,version,status,sites_count,update_version';

	/**
	 * Lists the plugins installed on the network, or still active on a site after their removal.
	 *
	 * Status: network (network-activated), local (active on some sites), unused, or missing (active on a site but no
	 * longer installed). Sites waiting for their first analysis are not counted, except for network-activated plugins.
	 *
	 * ## OPTIONS
	 *
	 * [--unused]
	 * : Only installed plugins that no site uses.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields: id, file, name, version, installed, network_active, sites_count, status, update_version.
	 * ---
	 * default: file,name,version,status,sites_count,update_version
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
	 *     # Plugins that could be deleted.
	 *     $ wp multisite-radar plugins list --unused
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$filter = get_flag_value( $assoc_args, 'unused', false ) ? [ 'status' => [ PluginsQuery::STATUS_UNUSED ] ] : [];
		try {
			$items = $this->plugin->plugins_query()->filtered( $filter );
		} catch ( \RuntimeException $error ) {
			self::read_failed( __METHOD__, $error );
			return;
		}

		format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, (string) ( $assoc_args['fields'] ?? self::DEFAULT_FIELDS ) );
	}
}
