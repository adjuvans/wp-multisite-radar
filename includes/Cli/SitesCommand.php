<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Plugin;
use function WP_CLI\Utils\format_items;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the sites of the network as last scanned.
 */
final class SitesCommand {

	private const DEFAULT_FIELDS = 'id,name,url,theme,users_count,content_count,media_count,last_activity_gmt,alert_level,alerts_count';

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Lists the sites of the network as last scanned.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Filter on name or URL.
	 *
	 * [--alert=<level>]
	 * : Only sites whose highest alert has this level.
	 * ---
	 * options:
	 *   - none
	 *   - info
	 *   - warning
	 *   - error
	 * ---
	 *
	 * [--theme=<stylesheet>]
	 * : Only sites using this theme (active or parent).
	 *
	 * [--plugin=<file>]
	 * : Only sites where this plugin is active, e.g. akismet/akismet.php.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields.
	 * ---
	 * default: id,name,url,theme,users_count,content_count,media_count,last_activity_gmt,alert_level,alerts_count
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
	 *     wp multisite-radar sites list --alert=error --format=csv
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$query = $this->plugin->sites_query();
		$items = [];
		$page  = 1;
		do {
			$result = $query->list(
				[
					'page'        => $page,
					'per_page'    => 100,
					'orderby'     => 'id',
					'search'      => (string) ( $assoc_args['search'] ?? '' ),
					'alert_level' => isset( $assoc_args['alert'] ) ? [ (string) $assoc_args['alert'] ] : [],
					'theme'       => (string) ( $assoc_args['theme'] ?? '' ),
					'plugin'      => (string) ( $assoc_args['plugin'] ?? '' ),
				]
			);
			foreach ( $result['items'] as $item ) {
				$items[] = self::flatten( $item );
			}
			++$page;
			$fetched = count( $items );
		} while ( [] !== $result['items'] && $fetched < $result['total'] );

		format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, (string) ( $assoc_args['fields'] ?? self::DEFAULT_FIELDS ) );
	}

	private static function flatten( array $item ): array {
		$item['theme']       = $item['theme']['stylesheet'];
		$item['status']      = implode( ',', array_keys( array_filter( $item['status'] ) ) );
		$item['alert_rules'] = implode( ',', $item['alert_rules'] );
		return $item;
	}
}
