<?php
namespace MultisiteRadar\Collector;

use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Support\PlainText;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Construit un SiteRecord par requêtes SQL agrégées sur les tables d'un site, sans charger ses plugins.
 */
final class SiteCollector {

	public const EXCLUDED_POST_TYPES = [ 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' ];
	public const EXCLUDED_TAXONOMIES = [ 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' ];

	private const CORE_POST_TYPES  = [ 'post', 'page', 'attachment' ];
	private const CORE_TAXONOMIES  = [ 'category', 'post_tag' ];
	private const DEFAULT_ROLES    = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];
	private const PRIVILEGED_ROLES = [ 'administrator', 'editor' ];
	private const PRIVILEGED_LIMIT = 50;
	private const ZERO_DATE        = '0000-00-00 00:00:00';

	/**
	 * Secondes accordées à la mesure du dossier d'envoi d'un site (filtre msradar_disk_budget).
	 */
	public const DISK_BUDGET = 2.0;

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @throws RuntimeException Si une requête sur les tables du site échoue.
	 */
	public function collect( int $site_id ): ?SiteRecord {
		global $wpdb;
		$site = get_site( $site_id );
		if ( null === $site ) {
			return null;
		}

		$prefix   = $wpdb->get_blog_prefix( $site_id );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$options = $this->read_options( $prefix );
			$counts  = $this->read_post_counts( $prefix );
			$terms   = $this->read_term_counts( $prefix );
			$last    = $this->read_last_content( $prefix );
			$users   = $this->read_users( $prefix, $this->role_names( $options[ $prefix . 'user_roles' ] ?? null ) );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		$disk = $this->measure_disk( $site_id, (int) $site->site_id );

		$raw_sitewide    = get_network_option( (int) $site->site_id, 'active_sitewide_plugins', [] );
		$network_plugins = Fingerprint::network_plugin_files( $raw_sitewide );
		$active_plugins  = Fingerprint::plugin_files( $options['active_plugins'] ?? null );
		$stylesheet      = self::string_option( $options, 'stylesheet' );
		$template        = self::string_option( $options, 'template' );
		$raw_registry    = $options[ RegistryProbe::OPTION ] ?? null;
		$status          = RegistryProbe::status(
			$raw_registry,
			Fingerprint::from_raw( $options['active_plugins'] ?? null, $raw_sitewide, $options['stylesheet'] ?? null, $options['template'] ?? null ),
			time()
		);
		$registry        = is_array( $raw_registry ) ? $raw_registry : [];
		$post_types      = $this->merge_post_types( $counts, (array) ( $registry['post_types'] ?? [] ), $status );
		$taxonomies      = $this->merge_taxonomies( $terms, (array) ( $registry['taxonomies'] ?? [] ), $status );
		$home            = self::string_option( $options, 'home' );
		$siteurl         = self::string_option( $options, 'siteurl' );
		$attachments     = $counts['attachment'] ?? [];

		$record                    = new SiteRecord();
		$record->site_id           = $site_id;
		$record->network_id        = (int) $site->site_id; // WP_Site::$site_id contient l'ID du réseau.
		$record->name              = PlainText::from_html( self::string_option( $options, 'blogname' ) );
		$record->siteurl           = $siteurl;
		$record->url               = '' !== $home ? $home : ( '' !== $siteurl ? $siteurl : 'http://' . $site->domain . $site->path );
		$record->is_public         = '1' === (string) $site->public;
		$record->is_archived       = '1' === (string) $site->archived;
		$record->is_spam           = '1' === (string) $site->spam;
		$record->is_deleted        = '1' === (string) $site->deleted;
		$record->theme_stylesheet  = $stylesheet;
		$record->theme_template    = $template;
		$record->users_count       = $users['total'];
		$record->admins_count      = (int) ( $users['by_role']['administrator'] ?? 0 );
		$record->content_count     = (int) array_sum( array_map( static fn ( array $type ): int => 'attachment' === $type['name'] ? 0 : $type['publish'], $post_types ) );
		$record->media_count       = (int) ( $attachments['inherit'] ?? 0 ) + (int) ( $attachments['publish'] ?? 0 );
		$record->disk_bytes        = $disk['bytes'];
		$record->disk_is_estimate  = $disk['estimate'];
		$record->last_activity_gmt = null !== $last ? $last['date_gmt'] : null;
		$record->registry_status   = $status;
		$record->data              = [
			'post_types'    => $post_types,
			'taxonomies'    => $taxonomies,
			'users'         => [
				'by_role'    => $users['by_role'],
				'privileged' => $users['privileged'],
			],
			'plugins_local' => array_values( array_diff( $active_plugins, $network_plugins ) ),
			'last_content'  => $last,
			'options'       => [
				'blog_public' => (int) ( $options['blog_public'] ?? 1 ),
				'siteurl'     => $siteurl,
				'home'        => $home,
				'locale'      => $this->locale( $options, (int) $site->site_id ),
			],
		];
		$record->dirty             = false;
		$record->dirty_since       = null;
		$record->scanned_at        = current_time( 'mysql', true );

		return $record;
	}

	private function read_options( string $prefix ): array {
		global $wpdb;
		$names = [ 'blogname', 'siteurl', 'home', 'stylesheet', 'template', 'active_plugins', 'blog_public', 'WPLANG', RegistryProbe::OPTION, $prefix . 'user_roles' ];
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, option_value FROM %i WHERE option_name IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')',
				array_merge( [ $prefix . 'options' ], $names )
			),
			ARRAY_A
		);
		$this->guard();

		$options = [];
		foreach ( (array) $rows as $row ) {
			$options[ (string) $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
		}
		return $options;
	}

	/**
	 * @return array<string, array<string, int>> type => statut => nombre.
	 */
	private function read_post_counts( string $prefix ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT post_type, post_status, COUNT(*) AS total FROM %i GROUP BY post_type, post_status', $prefix . 'posts' ),
			ARRAY_A
		);
		$this->guard();

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['post_type'] ][ (string) $row['post_status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * @return array<string, int> taxonomie => nombre de termes.
	 */
	private function read_term_counts( string $prefix ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT taxonomy, COUNT(*) AS total FROM %i GROUP BY taxonomy', $prefix . 'term_taxonomy' ),
			ARRAY_A
		);
		$this->guard();

		$terms = [];
		foreach ( (array) $rows as $row ) {
			$terms[ (string) $row['taxonomy'] ] = (int) $row['total'];
		}
		return $terms;
	}

	/**
	 * @return array{id: int, type: string, title: string, date_gmt: string}|null
	 */
	private function read_last_content( string $prefix ): ?array {
		global $wpdb;
		$types = array_values( array_filter( (array) $this->settings->get( 'scan.activity_post_types', [ 'post', 'page' ] ), 'is_string' ) );
		if ( [] === $types ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, post_type, post_title, post_modified_gmt, post_date_gmt FROM %i WHERE post_status = 'publish' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') ORDER BY post_modified_gmt DESC, ID DESC LIMIT 1',
				array_merge( [ $prefix . 'posts' ], $types )
			),
			ARRAY_A
		);
		$this->guard();

		if ( ! is_array( $row ) ) {
			return null;
		}
		$date = self::ZERO_DATE !== $row['post_modified_gmt'] ? (string) $row['post_modified_gmt'] : (string) $row['post_date_gmt'];
		if ( self::ZERO_DATE === $date ) {
			return null;
		}
		return [
			'id'       => (int) $row['ID'],
			'type'     => (string) $row['post_type'],
			'title'    => (string) $row['post_title'],
			'date_gmt' => $date,
		];
	}

	/**
	 * Dossier d'envoi du site, mesuré dans un budget de temps. C'est le seul endroit où le collecteur change de site
	 * (spec §3.3) : wp_upload_dir() dépend des options du site. Le site principal ne compte pas sites/, où vivent les
	 * autres sites, comme get_dirsize() du cœur.
	 *
	 * @return array{bytes: int|null, estimate: bool}
	 */
	private function measure_disk( int $site_id, int $network_id ): array {
		if ( ! $this->settings->get( 'scan.measure_disk', true ) ) {
			return [
				'bytes'    => null,
				'estimate' => false,
			];
		}

		switch_to_blog( $site_id );
		try {
			$uploads = wp_upload_dir( null, false );
		} finally {
			restore_current_blog();
		}
		$base = untrailingslashit( $uploads['basedir'] );
		if ( '' === $base ) {
			return [
				'bytes'    => null,
				'estimate' => false,
			];
		}

		$exclude = is_main_site( $site_id, $network_id ) ? [ $base . '/sites' ] : [];
		$budget  = (float) apply_filters( 'msradar_disk_budget', self::DISK_BUDGET, $site_id );
		$result  = DiskMeter::measure( $base, $exclude, $budget );

		return [
			'bytes'    => null === $result ? null : $result['bytes'],
			'estimate' => null !== $result && ! $result['complete'],
		];
	}

	/**
	 * Les valeurs de capacités sont très répétitives : un GROUP BY ramène quelques lignes même pour des milliers d'utilisateurs.
	 *
	 * @param string[] $roles Rôles connus du site.
	 * @return array{total: int, by_role: array<string, int>, privileged: array<int, array{id: int, login: string, roles: string[]}>}
	 */
	private function read_users( string $prefix, array $roles ): array {
		global $wpdb;
		$key  = $prefix . 'capabilities';
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT meta_value, COUNT(*) AS total FROM %i WHERE meta_key = %s GROUP BY meta_value', $wpdb->usermeta, $key ),
			ARRAY_A
		);
		$this->guard();

		$total   = 0;
		$by_role = [];
		foreach ( (array) $rows as $row ) {
			$user_roles = $this->roles_from_caps( $row['meta_value'], $roles );
			if ( [] === $user_roles ) {
				continue;
			}
			$count  = (int) $row['total'];
			$total += $count;
			foreach ( $user_roles as $role ) {
				$by_role[ $role ] = ( $by_role[ $role ] ?? 0 ) + $count;
			}
		}
		ksort( $by_role );

		$patterns = array_map( static fn ( string $role ): string => '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%', self::PRIVILEGED_ROLES );
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT u.ID, u.user_login, um.meta_value FROM %i um INNER JOIN %i u ON u.ID = um.user_id WHERE um.meta_key = %s AND (um.meta_value LIKE %s OR um.meta_value LIKE %s) ORDER BY u.ID ASC LIMIT %d',
				$wpdb->usermeta,
				$wpdb->users,
				$key,
				$patterns[0],
				$patterns[1],
				self::PRIVILEGED_LIMIT
			),
			ARRAY_A
		);
		$this->guard();

		$privileged = [];
		foreach ( (array) $rows as $row ) {
			$user_roles = $this->roles_from_caps( $row['meta_value'], $roles );
			if ( [] === array_intersect( $user_roles, self::PRIVILEGED_ROLES ) ) {
				continue;
			}
			$privileged[] = [
				'id'    => (int) $row['ID'],
				'login' => (string) $row['user_login'],
				'roles' => $user_roles,
			];
		}

		return [
			'total'      => $total,
			'by_role'    => $by_role,
			'privileged' => $privileged,
		];
	}

	/**
	 * @param mixed    $raw   Valeur sérialisée de wp_X_capabilities.
	 * @param string[] $roles Rôles connus.
	 * @return string[]
	 */
	private function roles_from_caps( $raw, array $roles ): array {
		$caps = maybe_unserialize( $raw );
		if ( ! is_array( $caps ) ) {
			return [];
		}
		$found = [];
		foreach ( $caps as $cap => $granted ) {
			if ( $granted && in_array( (string) $cap, $roles, true ) ) {
				$found[] = (string) $cap;
			}
		}
		return $found;
	}

	/**
	 * @param mixed $user_roles Valeur de l'option wp_X_user_roles.
	 * @return string[]
	 */
	private function role_names( $user_roles ): array {
		if ( ! is_array( $user_roles ) || [] === $user_roles ) {
			return self::DEFAULT_ROLES;
		}
		return array_map( 'strval', array_keys( $user_roles ) );
	}

	private function merge_post_types( array $counts, array $registered, string $status ): array {
		$excluded = (array) apply_filters( 'msradar_excluded_post_types', self::EXCLUDED_POST_TYPES );
		$items    = [];
		foreach ( self::names( $counts, $registered ) as $name ) {
			if ( in_array( $name, $excluded, true ) ) {
				continue;
			}
			$statuses = $counts[ $name ] ?? [];
			$items[]  = array_merge(
				$this->describe( $name, $registered[ $name ] ?? null, $status, self::CORE_POST_TYPES ),
				[
					'publish' => (int) ( $statuses['publish'] ?? 0 ),
					'total'   => (int) array_sum( $statuses ),
				]
			);
		}
		return $items;
	}

	private function merge_taxonomies( array $terms, array $registered, string $status ): array {
		$excluded = (array) apply_filters( 'msradar_excluded_taxonomies', self::EXCLUDED_TAXONOMIES );
		$items    = [];
		foreach ( self::names( $terms, $registered ) as $name ) {
			if ( in_array( $name, $excluded, true ) ) {
				continue;
			}
			$items[] = array_merge(
				$this->describe( $name, $registered[ $name ] ?? null, $status, self::CORE_TAXONOMIES ),
				[ 'count' => (int) ( $terms[ $name ] ?? 0 ) ]
			);
		}
		return $items;
	}

	/**
	 * @param mixed    $info   Entrée du registre pour ce nom, ou null.
	 * @param string[] $core   Noms natifs de WordPress.
	 */
	private function describe( string $name, $info, string $status, array $core ): array {
		$known = is_array( $info );
		if ( $known && is_array( $info['origin'] ?? null ) ) {
			$origin = [
				'kind' => (string) ( $info['origin']['kind'] ?? 'unknown' ),
				'slug' => (string) ( $info['origin']['slug'] ?? '' ),
			];
		} elseif ( in_array( $name, $core, true ) ) {
			$origin = [
				'kind' => 'core',
				'slug' => '',
			];
		} else {
			$origin = [
				'kind' => 'unknown',
				'slug' => '',
			];
		}

		return [
			'name'     => $name,
			'label'    => $known && is_string( $info['label'] ?? null ) && '' !== $info['label'] ? $info['label'] : $name,
			'origin'   => $origin,
			'builtin'  => $known ? (bool) ( $info['builtin'] ?? false ) : in_array( $name, $core, true ),
			'verified' => $known && RegistryProbe::STATUS_FRESH === $status,
		];
	}

	/**
	 * @return string[] Noms présents en base ou dans le registre, triés.
	 */
	private static function names( array $counts, array $registered ): array {
		$names = array_unique( array_merge( array_map( 'strval', array_keys( $counts ) ), array_map( 'strval', array_keys( $registered ) ) ) );
		sort( $names );
		return $names;
	}

	private function locale( array $options, int $network_id ): string {
		$locale = self::string_option( $options, 'WPLANG' );
		if ( '' === $locale ) {
			$locale = (string) get_network_option( $network_id, 'WPLANG', '' );
		}
		return '' !== $locale ? $locale : 'en_US';
	}

	private static function string_option( array $options, string $name ): string {
		return isset( $options[ $name ] ) && is_scalar( $options[ $name ] ) ? (string) $options[ $name ] : '';
	}

	private function guard(): void {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output; escaped at display.
		}
	}
}
