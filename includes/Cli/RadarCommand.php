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
	 * Scans sites of the current network and stores their data.
	 *
	 * Exactly one of --all, --dirty or --site is required.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Mark every site of the network for rescanning, then scan them.
	 *
	 * [--dirty]
	 * : Scan the sites of the network already marked for rescanning.
	 *
	 * [--site=<id>]
	 * : Rescan this site only, without touching the rest of the queue.
	 *
	 * [--probe]
	 * : First rebuild the registry of each site to scan in its own context (one WP-CLI process per site).
	 *
	 * ## EXAMPLES
	 *
	 *     # Full rescan of the network, registries included.
	 *     $ wp multisite-radar scan --all --probe
	 *
	 *     # Process the sites waiting in the queue.
	 *     $ wp multisite-radar scan --dirty
	 *
	 *     # Rescan a single site, registry included.
	 *     $ wp multisite-radar scan --site=5 --probe
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function scan( array $args, array $assoc_args ): void {
		if ( 1 !== count( array_intersect( [ 'all', 'dirty', 'site' ], array_keys( $assoc_args ) ) ) ) {
			WP_CLI::error( 'Specify exactly one of --all, --dirty or --site=<id>.' );
		}
		Installer::maybe_upgrade();
		$probe = isset( $assoc_args['probe'] );

		if ( isset( $assoc_args['site'] ) ) {
			$this->scan_one( (int) $assoc_args['site'], $probe );
			return;
		}

		$network_id = get_current_network_id();
		$sites      = $this->plugin->sites();
		if ( isset( $assoc_args['all'] ) ) {
			$this->plugin->queue()->request_full_scan( $network_id );
		}
		if ( $probe ) {
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
	 * Relève (si demandé) puis analyse un seul site sous le verrou, sans traiter le reste de la file.
	 */
	private function scan_one( int $site_id, bool $probe ): void {
		$network_id = get_current_network_id();
		$site       = get_site( $site_id );
		if ( null === $site ) {
			WP_CLI::error( sprintf( 'Site %d does not exist.', $site_id ) );
		}
		// Les réglages (types d'activité, règles d'alerte) sont ceux du réseau courant.
		if ( (int) $site->site_id !== $network_id ) {
			WP_CLI::error( sprintf( 'Site %d belongs to network %d: run the command with --url=<a site of that network>.', $site_id, (int) $site->site_id ) );
		}

		if ( $probe ) {
			$this->probe_sites( [ $site_id ] );
		}
		$sites = $this->plugin->sites();
		$sites->insert_pending( $site_id, $network_id, $site->domain . $site->path );
		$sites->mark_dirty( [ $site_id ] );

		$runner  = $this->plugin->runner();
		$scanned = false;
		$locked  = ! $runner->locked(
			static function () use ( $runner, $site_id, &$scanned ): void {
				$scanned = $runner->scan_site( $site_id );
			}
		);
		if ( $locked ) {
			WP_CLI::error( 'Another scan is running. Try again in a minute.' );
		}
		if ( ! $scanned ) {
			$record = $sites->find( $site_id );
			$error  = null !== $record ? (string) ( $record->data['scan_error']['message'] ?? '' ) : '';
			WP_CLI::error( sprintf( 'Site %d could not be scanned%s', $site_id, '' !== $error ? ': ' . $error : '.' ) );
		}
		WP_CLI::success( sprintf( 'Site %d scanned.', $site_id ) );
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
