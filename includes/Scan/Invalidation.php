<?php
namespace MultisiteRadar\Scan;

use MultisiteRadar\Install\Schema;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\ExtensionsRepository;
use MultisiteRadar\Storage\SitesRepository;
use WP_Post;
use WP_Site;

defined( 'ABSPATH' ) || exit;

/**
 * Marque « à rafraîchir » les sites touchés par un événement WordPress. Rien n'est analysé ici :
 * chaque gestionnaire coûte une requête UPDATE au plus.
 */
final class Invalidation {

	private SitesRepository $sites;
	private ExtensionsRepository $extensions;
	private Settings $settings;

	public function __construct( SitesRepository $sites, ExtensionsRepository $extensions, Settings $settings ) {
		$this->sites      = $sites;
		$this->extensions = $extensions;
		$this->settings   = $settings;
	}

	public function register(): void {
		add_action( 'activated_plugin', [ $this, 'on_plugin_change' ], 10, 2 );
		add_action( 'deactivated_plugin', [ $this, 'on_plugin_change' ], 10, 2 );
		add_action( 'switch_theme', [ $this, 'mark_current_site' ] );
		add_action( 'set_user_role', [ $this, 'mark_current_site' ] );
		add_action( 'deleted_user', [ $this, 'mark_current_site' ] );
		add_action( 'add_user_to_blog', [ $this, 'on_user_added' ], 10, 3 );
		add_action( 'remove_user_from_blog', [ $this, 'on_user_removed' ], 10, 2 );
		add_action( 'wpmu_delete_user', [ $this, 'on_network_user_deleted' ] );
		foreach ( [ 'blogname', 'blog_public', 'siteurl', 'home' ] as $option ) {
			add_action( 'update_option_' . $option, [ $this, 'mark_current_site' ] );
		}
		foreach ( [ 'make_spam_blog', 'make_ham_blog', 'archive_blog', 'unarchive_blog', 'make_delete_blog', 'make_undelete_blog' ] as $hook ) {
			add_action( $hook, [ $this, 'mark_site' ] );
		}
		add_action( 'wp_initialize_site', [ $this, 'on_site_initialized' ], 100 );
		add_action( 'wp_delete_site', [ $this, 'on_site_deleted' ] );
		add_action( 'transition_post_status', [ $this, 'on_post_status' ], 10, 3 );
	}

	public function mark_current_site(): void {
		if ( $this->ready() ) {
			$this->sites->mark_dirty( [ get_current_blog_id() ] );
		}
	}

	/**
	 * @param int|string $site_id
	 */
	public function mark_site( $site_id ): void {
		if ( $this->ready() ) {
			$this->sites->mark_dirty( [ (int) $site_id ] );
		}
	}

	/**
	 * @param string $plugin       Fichier du plugin.
	 * @param bool   $network_wide Activation ou désactivation sur tout le réseau.
	 */
	public function on_plugin_change( $plugin, $network_wide = false ): void {
		if ( ! $this->ready() ) {
			return;
		}
		if ( $network_wide ) {
			$this->sites->mark_all_dirty( get_current_network_id() );
			return;
		}
		$this->sites->mark_dirty( [ get_current_blog_id() ] );
	}

	/**
	 * @param int    $user_id
	 * @param string $role
	 * @param int    $blog_id
	 */
	public function on_user_added( $user_id, $role, $blog_id ): void {
		$this->mark_site( $blog_id );
	}

	/**
	 * @param int $user_id
	 * @param int $blog_id
	 */
	public function on_user_removed( $user_id, $blog_id ): void {
		$this->mark_site( $blog_id );
	}

	/**
	 * Appelé avant la suppression : on connaît encore les sites de l'utilisateur.
	 *
	 * @param int $user_id
	 */
	public function on_network_user_deleted( $user_id ): void {
		if ( $this->ready() ) {
			$this->sites->mark_dirty( array_map( 'intval', array_keys( get_blogs_of_user( (int) $user_id, true ) ) ) );
		}
	}

	public function on_site_initialized( WP_Site $site ): void {
		if ( $this->ready() ) {
			// WP_Site::$site_id contient l'ID du réseau.
			$this->sites->insert_pending( (int) $site->blog_id, (int) $site->site_id, $site->domain . $site->path );
		}
	}

	public function on_site_deleted( WP_Site $site ): void {
		if ( $this->ready() ) {
			$this->sites->delete( (int) $site->blog_id );
			$this->extensions->delete_for_site( (int) $site->blog_id );
		}
	}

	/**
	 * Une publication ne relance pas d'analyse : elle avance seulement la date de dernière activité.
	 *
	 * @param string $new_status
	 * @param string $old_status
	 * @param mixed  $post
	 */
	public function on_post_status( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || ! $post instanceof WP_Post || ! $this->ready() ) {
			return;
		}
		$types = (array) $this->settings->get( 'scan.activity_post_types', [ 'post', 'page' ] );
		if ( ! in_array( $post->post_type, $types, true ) ) {
			return;
		}
		$gmt = '' !== $post->post_modified_gmt && '0000-00-00 00:00:00' !== $post->post_modified_gmt
			? $post->post_modified_gmt
			: current_time( 'mysql', true );
		$this->sites->update_last_activity( get_current_blog_id(), $gmt );
	}

	private function ready(): bool {
		return Schema::is_current();
	}
}
