<?php
namespace MultisiteRadar\Cli;

use WP_CLI;
use function WP_CLI\Utils\format_items;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the alerts of the network, one line per site and rule.
 */
final class AlertsCommand extends Command {

	private const DEFAULT_FIELDS = 'site_id,site,rule,severity,message';

	/**
	 * Lists the alerts of the network, one line per site and rule.
	 *
	 * Only enabled rules are listed, with the severity of the current settings.
	 *
	 * ## OPTIONS
	 *
	 * [--severity=<severity>]
	 * : Only alerts of this severity.
	 * ---
	 * options:
	 *   - error
	 *   - warning
	 *   - info
	 * ---
	 *
	 * [--rule=<rule>]
	 * : Only alerts of this rule, e.g. inactive.
	 *
	 * [--search=<text>]
	 * : Filter on the site name or URL.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields: site_id, site, url, rule, label, severity, message.
	 * ---
	 * default: site_id,site,rule,severity,message
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
	 *     # Errors only, as CSV.
	 *     $ wp multisite-radar alerts --severity=error --format=csv
	 *
	 *     # Sites hidden from search engines.
	 *     $ wp multisite-radar alerts --rule=search_hidden
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$rule = (string) ( $assoc_args['rule'] ?? '' );
		if ( '' !== $rule && null === $this->plugin->rules()->get( $rule ) ) {
			WP_CLI::error( sprintf( 'Unknown alert rule: %s', $rule ) );
			return;
		}
		$query  = $this->plugin->alerts_query();
		$filter = [
			'severity' => isset( $assoc_args['severity'] ) ? [ (string) $assoc_args['severity'] ] : [],
			'rule'     => '' !== $rule ? [ $rule ] : [],
			'search'   => (string) ( $assoc_args['search'] ?? '' ),
			'orderby'  => 'rule',
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

		format_items( (string) ( $assoc_args['format'] ?? 'table' ), array_map( [ Rows::class, 'alert' ], $items ), (string) ( $assoc_args['fields'] ?? self::DEFAULT_FIELDS ) );
	}
}
