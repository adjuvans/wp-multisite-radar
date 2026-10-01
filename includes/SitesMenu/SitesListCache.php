<?php
namespace MultisiteRadar\SitesMenu;

use MultisiteRadar\Support\PlainText;
use WP_Site;

defined( 'ABSPATH' ) || exit;

/**
 * Liste des sites publics du réseau (identifiant, nom, adresse, inscription), sans limite de nombre.
 *
 * Stockée dans un transient réseau propre à chaque réseau. Elle est vidée quand un site est créé, supprimé ou modifié
 * (archivage, spam, visibilité, chemin), renommé, ou change d'adresse. La reconstruction lit wp_blogs puis le nom
 * et l'adresse de chaque site, par tranches de 100 sites en une requête UNION ALL, sans switch_to_blog().
 */
final class SitesListCache {

	public const PREFIX = 'msradar_sites_list_';

	private const CHUNK = 100;

	public static function name( int $network_id ): string {
		return self::PREFIX . $network_id;
	}

	public function register(): void {
		add_action( 'wp_initialize_site', [ $this, 'on_site_changed' ], 20 );
		add_action( 'wp_delete_site', [ $this, 'on_site_changed' ] );
		add_action( 'wp_update_site', [ $this, 'on_site_changed' ] );
		add_action( 'update_option_blogname', [ $this, 'flush_current' ] );
		add_action( 'update_option_home', [ $this, 'flush_current' ] );
	}

	/**
	 * @return array<int, array{id: int, name: string, url: string, registered: string}>
	 */
	public function get(): array {
		$network_id = get_current_network_id();
		$cached     = get_site_transient( self::name( $network_id ) );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			$sites = $this->build( $network_id );
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
			return []; // Pas de mise en cache : une panne passagère ne doit pas vider le menu pendant un jour.
		}
		set_site_transient( self::name( $network_id ), $sites, DAY_IN_SECONDS );
		return $sites;
	}

	/**
	 * Les noms sont en texte brut (le cœur stocke le titre échappé) : le rendu les échappe.
	 *
	 * @throws \RuntimeException Si la lecture de wp_blogs échoue.
	 * @return array<int, array{id: int, name: string, url: string, registered: string}>
	 */
	public function build( int $network_id ): array {
		global $wpdb;
		$blogs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT blog_id, domain, path, registered FROM %i WHERE site_id = %d AND public = 1 AND archived = '0' AND spam = 0 AND deleted = 0 ORDER BY blog_id ASC",
				$wpdb->blogs,
				$network_id
			),
			ARRAY_A
		);
		// empty() et non une comparaison stricte avec '' : PHPStan type last_error sans chaîne vide.
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Reading the sites table failed: ' . esc_html( $wpdb->last_error ) );
		}
		$blogs = (array) $blogs;

		$sites = [];
		foreach ( array_chunk( $blogs, self::CHUNK ) as $chunk ) {
			$options = $this->read_options( $chunk );
			foreach ( $chunk as $blog ) {
				$id = (int) $blog['blog_id'];
				if ( ! isset( $options[ $id ] ) ) {
					continue; // Tables du site absentes : réseau abîmé, le site est ignoré.
				}
				$home    = (string) ( $options[ $id ]['home'] ?? '' );
				$sites[] = [
					'id'         => $id,
					'name'       => PlainText::from_html( (string) ( $options[ $id ]['blogname'] ?? '' ) ),
					'url'        => '' !== $home ? $home : set_url_scheme( 'http://' . $blog['domain'] . $blog['path'] ),
					'registered' => (string) $blog['registered'],
				];
			}
		}
		return $sites;
	}

	public function flush( int $network_id ): void {
		$name = self::name( $network_id );
		if ( get_current_network_id() === $network_id ) {
			delete_site_transient( $name );
			return;
		}
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $name, 'site-transient' );
			return;
		}
		delete_network_option( $network_id, '_site_transient_' . $name );
		delete_network_option( $network_id, '_site_transient_timeout_' . $name );
	}

	public function on_site_changed( WP_Site $site ): void {
		$this->flush( (int) $site->network_id );
	}

	public function flush_current(): void {
		$this->flush( get_current_network_id() );
	}

	/**
	 * Nom et adresse de chaque site, en une requête par tranche.
	 * Si une table manque, la requête groupée échoue : la tranche est relue site par site, pour ne perdre que le site abîmé.
	 *
	 * @param array[] $blogs Lignes de wp_blogs.
	 * @return array<int, array<string, string>> blog_id => nom d'option => valeur.
	 */
	private function read_options( array $blogs ): array {
		global $wpdb;
		$select = 'SELECT %d AS blog_id, option_name, option_value FROM %i WHERE option_name IN (%s, %s)';
		$parts  = [];
		$params = [];
		foreach ( $blogs as $blog ) {
			$id      = (int) $blog['blog_id'];
			$parts[] = $select;
			array_push( $params, $id, $wpdb->get_blog_prefix( $id ) . 'options', 'blogname', 'home' );
		}

		$suppress = $wpdb->suppress_errors( true );
		try {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', $parts ), $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $parts only holds the fixed SELECT above, with placeholders.
			// empty() et non une comparaison stricte avec '' : PHPStan type last_error sans chaîne vide.
			if ( ! empty( $wpdb->last_error ) ) {
				$rows = [];
				foreach ( $blogs as $blog ) {
					$id     = (int) $blog['blog_id'];
					$single = $wpdb->get_results( $wpdb->prepare( $select, $id, $wpdb->get_blog_prefix( $id ) . 'options', 'blogname', 'home' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SELECT with placeholders.
					// Même raison que plus haut : empty() plutôt que '' === last_error.
					if ( empty( $wpdb->last_error ) ) {
						$rows = array_merge( $rows, (array) $single );
					}
				}
			}
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		$options = [];
		foreach ( $rows as $row ) {
			$options[ (int) $row['blog_id'] ][ (string) $row['option_name'] ] = (string) $row['option_value'];
		}
		return $options;
	}
}
