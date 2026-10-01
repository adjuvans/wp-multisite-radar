<?php
namespace MultisiteRadar\Cli;

use MultisiteRadar\Collector\RegistryProbe;
use MultisiteRadar\Install\Installer;
use MultisiteRadar\Plugin;
use WP_CLI;
use function WP_CLI\Utils\make_progress_bar;

defined( 'ABSPATH' ) || exit;

/**
 * Scans and inspects the network with Multisite Radar.
 */
final class RadarCommand {

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public static function register( Plugin $plugin ): void {
		WP_CLI::add_command( 'multisite-radar', new self( $plugin ) );
		WP_CLI::add_command( 'multisite-radar sites', new SitesCommand( $plugin ) );
	}

	/**
	 * Scans sites and stores their data.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Rescan every site of the network.
	 *
	 * [--site=<id>]
	 * : Rescan a single site.
	 *
	 * [--probe]
	 * : First rebuild each site's registry in its own context (one WP-CLI process per site).
	 *
	 * ## EXAMPLES
	 *
	 *     wp multisite-radar scan --all --probe
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function scan( array $args, array $assoc_args ): void {
		Installer::maybe_upgrade();
		$network_id = get_current_network_id();
		$sites      = $this->plugin->sites();

		if ( isset( $assoc_args['site'] ) ) {
			$site_id = (int) $assoc_args['site'];
			if ( null === get_site( $site_id ) ) {
				WP_CLI::error( sprintf( 'Site %d does not exist.', $site_id ) );
			}
			$sites->seed_from_blogs( $network_id );
			$sites->mark_dirty( [ $site_id ] );
		} elseif ( isset( $assoc_args['all'] ) ) {
			$this->plugin->queue()->request_full_scan( $network_id );
		}

		if ( isset( $assoc_args['probe'] ) ) {
			$this->probe_sites( $sites->dirty_ids( $network_id ) );
		}

		$total = $sites->count_dirty( $network_id );
		if ( 0 === $total ) {
			WP_CLI::success( 'Nothing to scan.' );
			return;
		}

		$progress = make_progress_bar( 'Scanning sites', $total );
		$failed   = 0;
		$result   = $this->plugin->runner()->run(
			(float) PHP_INT_MAX,
			static function ( int $site_id, bool $ok ) use ( $progress, &$failed ): void {
				if ( ! $ok ) {
					++$failed;
				}
				$progress->tick();
			}
		);
		$progress->finish();

		if ( $result['locked'] ) {
			WP_CLI::error( 'Another scan is running. Try again in a minute.' );
		}
		if ( $failed > 0 ) {
			WP_CLI::warning( sprintf( '%d site(s) failed or no longer exist.', $failed ) );
		}
		WP_CLI::success( sprintf( '%d site(s) scanned.', $result['processed'] ) );
	}

	/**
	 * Rebuilds the registry of the current site. Run it with --url=<site>.
	 *
	 * ## EXAMPLES
	 *
	 *     wp multisite-radar probe --url=example.org/blog/
	 */
	public function probe(): void {
		$this->plugin->probe()->run();
		$registry = get_option( RegistryProbe::OPTION );
		$registry = is_array( $registry ) ? $registry : [];
		WP_CLI::success(
			sprintf(
				'Registry rebuilt for site %d: %d post types, %d taxonomies.',
				get_current_blog_id(),
				count( (array) ( $registry['post_types'] ?? [] ) ),
				count( (array) ( $registry['taxonomies'] ?? [] ) )
			)
		);
	}

	/**
	 * @param int[] $site_ids
	 */
	private function probe_sites( array $site_ids ): void {
		$progress = make_progress_bar( 'Probing registries', count( $site_ids ) );
		foreach ( $site_ids as $site_id ) {
			$site = get_site( $site_id );
			if ( null !== $site ) {
				$result = WP_CLI::runcommand(
					'multisite-radar probe',
					[
						'launch'       => true,
						'return'       => 'all',
						'exit_error'   => false,
						'command_args' => [ '--url=' . $site->domain . $site->path ],
					]
				);
				if ( 0 !== (int) $result->return_code ) {
					WP_CLI::warning( sprintf( 'Probe failed for site %d: %s', $site_id, trim( (string) $result->stderr ) ) );
				}
			}
			$progress->tick();
		}
		$progress->finish();
	}
}
