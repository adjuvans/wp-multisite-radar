<?php
namespace MultisiteRadar\Cli;

use function WP_CLI\Utils\format_items;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the sites of the network as last scanned.
 */
final class SitesCommand extends Command {

	private const DEFAULT_FIELDS = 'id,name,url,theme,users_count,content_count,media_count,last_activity_gmt,alert_level,alerts_count';

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
		$query  = $this->plugin->sites_query();
		$filter = [
			'orderby'     => 'id',
			'search'      => (string) ( $assoc_args['search'] ?? '' ),
			'alert_level' => isset( $assoc_args['alert'] ) ? [ (string) $assoc_args['alert'] ] : [],
			'theme'       => (string) ( $assoc_args['theme'] ?? '' ),
			'plugin'      => (string) ( $assoc_args['plugin'] ?? '' ),
		];
		try {
			$items = Pages::collect(
				static function ( int $page, int $per_page ) use ( $query, $filter ): array {
					return $query->list(
						array_merge(
							$filter,
							[
								'page'     => $page,
								'per_page' => $per_page,
							]
						)
					);
				}
			);
		} catch ( \RuntimeException $error ) {
			self::read_failed( __METHOD__, $error );
			return;
		}

		format_items( (string) ( $assoc_args['format'] ?? 'table' ), array_map( [ Rows::class, 'site' ], $items ), (string) ( $assoc_args['fields'] ?? self::DEFAULT_FIELDS ) );
	}
}
