<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Alerts\AlertFormatter;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Collector\RegistryProbe;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\ExtensionsRepository;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture des sites pour l'extérieur (REST, WP-CLI, plus tard l'interface) : normalise les arguments et met en forme.
 */
final class SitesQuery {

	public const STATUSES          = [ 'public', 'private', 'archived', 'spam', 'deleted' ];
	public const REGISTRY_STATUSES = [ RegistryProbe::STATUS_FRESH, RegistryProbe::STATUS_STALE, RegistryProbe::STATUS_MISSING ];
	public const MAX_PAGE          = 100000;
	public const MAX_INCLUDE       = 500;

	private SitesRepository $sites;
	private ExtensionsRepository $extensions;
	private AlertFormatter $formatter;
	private Settings $settings;

	public function __construct( SitesRepository $sites, ExtensionsRepository $extensions, AlertFormatter $formatter, Settings $settings ) {
		$this->sites      = $sites;
		$this->extensions = $extensions;
		$this->formatter  = $formatter;
		$this->settings   = $settings;
	}

	public static function defaults(): array {
		return [
			'page'            => 1,
			'per_page'        => 20,
			'search'          => '',
			'orderby'         => 'name',
			'order'           => 'asc',
			'alert_level'     => [],
			'status'          => [],
			'theme'           => '',
			'plugin'          => '',
			'has_users'       => null,
			'inactive_since'  => null,
			'registry_status' => [],
			'rule'            => '',
			'include'         => [],
		];
	}

	/**
	 * Arguments publics (REST, WP-CLI, exports) → arguments du dépôt, bornés et validés.
	 */
	public function normalize( array $args, int $max_per_page = 100 ): array {
		$args    = array_merge( self::defaults(), $args );
		$plugin  = (string) $args['plugin'];
		$orderby = (string) $args['orderby'];
		$include = array_values( array_unique( array_filter( array_map( 'intval', (array) $args['include'] ), static fn ( int $id ): bool => $id > 0 ) ) );

		return [
			'network_id'      => get_current_network_id(),
			'page'            => min( self::MAX_PAGE, max( 1, (int) $args['page'] ) ),
			'per_page'        => min( $max_per_page, max( 1, (int) $args['per_page'] ) ),
			'search'          => trim( (string) $args['search'] ),
			'orderby'         => isset( SitesRepository::ORDERBY[ $orderby ] ) ? $orderby : 'name',
			'order'           => 'desc' === strtolower( (string) $args['order'] ) ? 'desc' : 'asc',
			'alert_level'     => array_values( array_unique( array_map( [ Severity::class, 'level' ], array_intersect( array_map( 'strval', (array) $args['alert_level'] ), Severity::names() ) ) ) ),
			'status'          => array_values( array_intersect( array_map( 'strval', (array) $args['status'] ), self::STATUSES ) ),
			'theme'           => (string) $args['theme'],
			'plugin'          => $this->is_network_active( $plugin ) ? '' : $plugin,
			'has_users'       => null === $args['has_users'] ? null : (bool) $args['has_users'],
			'inactive_since'  => null === $args['inactive_since'] ? null : (string) $args['inactive_since'],
			'registry_status' => array_values( array_intersect( array_map( 'strval', (array) $args['registry_status'] ), self::REGISTRY_STATUSES ) ),
			'rule'            => (string) $args['rule'],
			'include'         => array_slice( $include, 0, self::MAX_INCLUDE ),
		];
	}

	/**
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$result = $this->sites->query( $this->normalize( $args ) );
		return [
			'items' => array_map( [ $this, 'summary' ], $result['items'] ),
			'total' => $result['total'],
		];
	}

	/**
	 * Parcourt tous les sites qui correspondent aux filtres, par tranches et sans le plafond de 100 par page (exports).
	 *
	 * @param callable $consumer Appelé avec chaque site mis en forme par summary().
	 * @return int Nombre de sites parcourus.
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function each( array $args, callable $consumer, int $chunk = 500 ): int {
		$chunk = max( 1, $chunk );
		$query = $this->normalize( $args, $chunk );
		$count = 0;
		$page  = 1;
		while ( true ) {
			$query['page']     = $page;
			$query['per_page'] = $chunk;
			$result            = $this->sites->query( $query );
			foreach ( $result['items'] as $record ) {
				$consumer( $this->summary( $record ) );
				++$count;
			}
			if ( count( $result['items'] ) < $chunk || $count >= $result['total'] ) {
				return $count;
			}
			++$page;
		}
	}

	public function get( int $site_id ): ?array {
		$record = $this->sites->find( $site_id );
		if ( null === $record || get_current_network_id() !== $record->network_id ) {
			return null;
		}

		$data = $record->data;
		$last = is_array( $data['last_content'] ?? null ) ? $data['last_content'] : null;
		if ( null !== $last ) {
			$last['date_gmt'] = self::date( (string) ( $last['date_gmt'] ?? '' ) );
		}

		return array_merge(
			$this->summary( $record ),
			[
				'post_types'   => $this->filter_custom( (array) ( $data['post_types'] ?? [] ) ),
				'taxonomies'   => $this->filter_custom( (array) ( $data['taxonomies'] ?? [] ) ),
				'users'        => is_array( $data['users'] ?? null ) ? $data['users'] : [
					'by_role'    => [],
					'privileged' => [],
				],
				'last_content' => $last,
				'options'      => is_array( $data['options'] ?? null ) ? $data['options'] : [],
				'alerts'       => $this->formatter->format( (array) ( $data['alerts'] ?? [] ) ),
				'extensions'   => $this->extensions_for( $record ),
				'scan_error'   => is_array( $data['scan_error'] ?? null ) ? [
					'message' => (string) ( $data['scan_error']['message'] ?? '' ),
					'at_gmt'  => self::date( (string) ( $data['scan_error']['at_gmt'] ?? '' ) ),
				] : null,
			]
		);
	}

	/**
	 * Identité d'un site, commune aux listes, à la fiche et aux alertes.
	 * Le nom de repli est construit ici, dans la langue du lecteur : le collecteur stocke le nom brut, même vide.
	 * Le nom est décodé aussi ici : les lignes enregistrées avant la 2.0.0-beta.2 gardent le titre échappé par le cœur.
	 *
	 * @return array{id: int, name: string, url: string, admin_url: string}
	 */
	public static function identity( SiteRecord $record ): array {
		$base = self::absolute( '' !== $record->siteurl ? $record->siteurl : $record->url );
		$name = PlainText::from_html( $record->name );
		if ( '' === trim( $name ) ) {
			/* translators: %d: site ID. */
			$name = sprintf( __( 'Site #%d', 'multisite-radar' ), $record->site_id );
		}
		return [
			'id'        => $record->site_id,
			'name'      => $name,
			'url'       => self::absolute( $record->url ),
			'admin_url' => '' !== $base ? trailingslashit( $base ) . 'wp-admin/' : '',
		];
	}

	public function summary( SiteRecord $record ): array {
		return array_merge(
			self::identity( $record ),
			[
				'status'            => [
					'public'   => $record->is_public,
					'archived' => $record->is_archived,
					'spam'     => $record->is_spam,
					'deleted'  => $record->is_deleted,
				],
				'theme'             => [
					'stylesheet' => $record->theme_stylesheet,
					'template'   => $record->theme_template,
				],
				'users_count'       => $record->users_count,
				'admins_count'      => $record->admins_count,
				'content_count'     => $record->content_count,
				'media_count'       => $record->media_count,
				'disk_bytes'        => $record->disk_bytes,
				'db_bytes'          => $record->db_bytes,
				'autoload_bytes'    => $record->autoload_bytes,
				'last_activity_gmt' => self::date( (string) $record->last_activity_gmt ),
				'alert_level'       => Severity::name( $record->alert_level ),
				'alerts_count'      => $record->alerts_count,
				'alert_rules'       => $record->alert_rule_ids(),
				'registry_status'   => $record->registry_status,
				'pending'           => null === $record->scanned_at,
				'dirty'             => $record->dirty,
				'scanned_at_gmt'    => self::date( (string) $record->scanned_at ),
			]
		);
	}

	/**
	 * Les lignes jamais analysées stockent domaine + chemin sans schéma.
	 */
	private static function absolute( string $url ): string {
		if ( '' === $url || false !== strpos( $url, '://' ) ) {
			return $url;
		}
		return set_url_scheme( 'http://' . $url );
	}

	private static function date( string $gmt ): ?string {
		return '' === $gmt || '0000-00-00 00:00:00' === $gmt ? null : mysql_to_rfc3339( $gmt );
	}

	private function is_network_active( string $plugin ): bool {
		return '' !== $plugin && array_key_exists( $plugin, (array) get_site_option( 'active_sitewide_plugins', [] ) );
	}

	/**
	 * Réglage « limiter l'analyse aux plugins » : garde les éléments natifs et ceux des plugins choisis.
	 */
	private function filter_custom( array $items ): array {
		$allowed = array_values( array_filter( (array) $this->settings->get( 'scan.analysis_plugins', [] ), 'is_string' ) );
		if ( [] === $allowed ) {
			return array_values( $items );
		}
		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $allowed ): bool {
					if ( ! is_array( $item ) ) {
						return false;
					}
					if ( ! empty( $item['builtin'] ) ) {
						return true;
					}
					return 'plugin' === ( $item['origin']['kind'] ?? '' ) && in_array( $item['origin']['slug'] ?? '', $allowed, true );
				}
			)
		);
	}

	private function extensions_for( SiteRecord $record ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		$plugins   = [];
		foreach ( $this->extensions->for_site( $record->site_id ) as $row ) {
			if ( ExtensionsRepository::TYPE_PLUGIN !== $row['type'] ) {
				continue;
			}
			$plugin    = $installed[ $row['slug'] ] ?? null;
			$plugins[] = [
				'file'      => $row['slug'],
				'name'      => null !== $plugin ? (string) $plugin['Name'] : $row['slug'],
				'version'   => null !== $plugin ? (string) $plugin['Version'] : '',
				'installed' => null !== $plugin,
			];
		}

		return [
			'plugins_local'         => $plugins,
			'network_plugins_count' => count( (array) get_site_option( 'active_sitewide_plugins', [] ) ),
			'theme'                 => array_merge(
				[
					'stylesheet' => $record->theme_stylesheet,
					'template'   => $record->theme_template,
				],
				$this->theme( $record->theme_stylesheet )
			),
		];
	}

	private function theme( string $stylesheet ): array {
		$theme = '' !== $stylesheet ? wp_get_theme( $stylesheet ) : null;
		if ( null === $theme || ! $theme->exists() ) {
			return [
				'name'      => $stylesheet,
				'version'   => '',
				'installed' => false,
			];
		}
		return [
			'name'      => PlainText::from_html( wp_strip_all_tags( (string) $theme->get( 'Name' ) ) ),
			'version'   => (string) $theme->get( 'Version' ),
			'installed' => true,
		];
	}
}
