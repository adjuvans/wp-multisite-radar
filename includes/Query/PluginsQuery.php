<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Collector\Fingerprint;
use MultisiteRadar\Storage\ExtensionsRepository;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Inventaire des plugins du réseau courant : installés (hors MU-plugins et drop-ins), ou encore actifs sur un site
 * alors qu'ils ont disparu du disque. Le nombre de sites ne compte que les sites analysés (écart E8 du plan M3).
 */
final class PluginsQuery {

	public const STATUS_NETWORK = 'network';
	public const STATUS_LOCAL   = 'local';
	public const STATUS_UNUSED  = 'unused';
	public const STATUS_MISSING = 'missing';
	public const STATUSES       = [ self::STATUS_NETWORK, self::STATUS_LOCAL, self::STATUS_UNUSED, self::STATUS_MISSING ];

	private ExtensionsRepository $extensions;
	private SitesRepository $sites;

	public function __construct( ExtensionsRepository $extensions, SitesRepository $sites ) {
		$this->extensions = $extensions;
		$this->sites      = $sites;
	}

	public static function defaults(): array {
		return [
			'page'       => 1,
			'per_page'   => 20,
			'search'     => '',
			'status'     => [],
			'has_update' => false,
			'orderby'    => 'name',
			'order'      => 'asc',
		];
	}

	/**
	 * Identifiant public d'un plugin : son fichier sans « .php », comme la route wp/v2/plugins du cœur.
	 * Aucune URL de l'API ne contient ainsi « .php/ », que certains serveurs confient à PHP (écart E1 du plan M3).
	 */
	public static function id( string $file ): string {
		return '.php' === substr( $file, -4 ) ? substr( $file, 0, -4 ) : $file;
	}

	/**
	 * Plugins installés, nom en texte brut. get_plugins() ignore déjà les MU-plugins et les drop-ins.
	 *
	 * @return array<string, array{name: string, version: string}> Fichier => nom et version.
	 */
	public static function installed(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = [];
		foreach ( get_plugins() as $file => $data ) {
			$plugins[ (string) $file ] = [
				'name'    => PlainText::from_html( wp_strip_all_tags( (string) ( $data['Name'] ?? '' ) ) ),
				'version' => (string) ( $data['Version'] ?? '' ),
			];
		}
		return $plugins;
	}

	/**
	 * Tous les plugins du réseau, triés par fichier.
	 *
	 * @return array<string, array> Fichier => plugin (forme REST).
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function all(): array {
		$network_id = get_current_network_id();
		$installed  = self::installed();
		$counts     = $this->extensions->plugin_counts( $network_id );
		$network    = Fingerprint::network_plugin_files( get_site_option( 'active_sitewide_plugins', [] ) );
		$updates    = InventoryList::updates( 'update_plugins' );
		$total      = $this->sites->count_all( $network_id );

		$files = array_values( array_unique( array_map( 'strval', array_merge( array_keys( $installed ), array_keys( $counts ), $network ) ) ) );
		sort( $files, SORT_STRING );

		$items = [];
		foreach ( $files as $file ) {
			$plugin         = $installed[ $file ] ?? null;
			$network_active = in_array( $file, $network, true );
			$sites_count    = $network_active ? $total : ( $counts[ $file ] ?? 0 );
			if ( null === $plugin ) {
				$status = self::STATUS_MISSING;
			} elseif ( $network_active ) {
				$status = self::STATUS_NETWORK;
			} elseif ( $sites_count > 0 ) {
				$status = self::STATUS_LOCAL;
			} else {
				$status = self::STATUS_UNUSED;
			}
			$items[ $file ] = [
				'id'             => self::id( $file ),
				'file'           => $file,
				'name'           => null !== $plugin && '' !== $plugin['name'] ? $plugin['name'] : $file,
				'version'        => null !== $plugin ? $plugin['version'] : '',
				'installed'      => null !== $plugin,
				'network_active' => $network_active,
				'sites_count'    => $sites_count,
				'status'         => $status,
				'update_version' => null !== $plugin ? ( $updates[ $file ] ?? null ) : null,
			];
		}
		return $items;
	}

	/**
	 * Plugins qui correspondent aux filtres, triés, sans pagination (exports).
	 *
	 * @return array[]
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function filtered( array $args ): array {
		$args     = array_merge( self::defaults(), $args );
		$search   = trim( (string) $args['search'] );
		$statuses = array_values( array_intersect( array_map( 'strval', (array) $args['status'] ), self::STATUSES ) );
		$updates  = (bool) $args['has_update'];
		$items    = array_filter(
			$this->all(),
			static function ( array $item ) use ( $search, $statuses, $updates ): bool {
				return InventoryList::matches( $search, $item['name'], $item['file'] )
					&& ( [] === $statuses || in_array( $item['status'], $statuses, true ) )
					&& ( ! $updates || null !== $item['update_version'] );
			}
		);
		return InventoryList::sort( array_values( $items ), (string) $args['orderby'], (string) $args['order'] );
	}

	/**
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$args = array_merge( self::defaults(), $args );
		return InventoryList::slice( $this->filtered( $args ), (int) $args['page'], (int) $args['per_page'] );
	}

	/**
	 * @param string $id Identifiant public (fichier sans « .php »).
	 * @return array|null Null si le plugin n'est ni installé ni actif sur un site du réseau.
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function find( string $id ): ?array {
		foreach ( $this->all() as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * @return array{installed: int, network: int, unused: int, missing: int, updates: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function summary(): array {
		$summary = [
			'installed' => 0,
			'network'   => 0,
			'unused'    => 0,
			'missing'   => 0,
			'updates'   => 0,
		];
		foreach ( $this->all() as $item ) {
			$summary['installed'] += $item['installed'] ? 1 : 0;
			$summary['network']   += self::STATUS_NETWORK === $item['status'] ? 1 : 0;
			$summary['unused']    += self::STATUS_UNUSED === $item['status'] ? 1 : 0;
			$summary['missing']   += self::STATUS_MISSING === $item['status'] ? 1 : 0;
			$summary['updates']   += null !== $item['update_version'] ? 1 : 0;
		}
		return $summary;
	}
}
