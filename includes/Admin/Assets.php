<?php
namespace MultisiteRadar\Admin;

use MultisiteRadar\Capabilities;
use MultisiteRadar\Export\ExportHandler;
use MultisiteRadar\Settings\Preferences;

defined( 'ABSPATH' ) || exit;

/**
 * Charge le point d'entrée compilé de la vue affichée et lui passe sa configuration, y compris les données préchargées.
 */
final class Assets {

	/**
	 * DataViews, embarqué dans le chunk commun (SHARED_CHUNK), traduit ses textes dans le domaine « default » : ses chaînes sont
	 * extraites dans les traductions du plugin (bin/i18n.sh), recopiées ici dans ce domaine avant l'exécution du
	 * bundle. Sans traduction chargée (anglais), il n'y a rien à recopier.
	 */
	public const SHARE_TRANSLATIONS = "( function ( i18n ) { var data = i18n.getLocaleData( 'multisite-radar' ); if ( data && Object.keys( data ).length > 1 ) { i18n.setLocaleData( data, 'default' ); } } )( window.wp.i18n );";

	/**
	 * Chunk webpack commun aux vues qui affichent DataViews (webpack.config.js) : DataViews et ses dépendances,
	 * environ 2 Mo, téléchargés une fois puis servis par le cache du navigateur sur les autres pages.
	 */
	public const SHARED_CHUNK  = 'dataviews';
	public const SHARED_HANDLE = 'msradar-dataviews';

	/**
	 * Vues sans DataViews, qui ne chargent pas le chunk commun (LIGHT_VIEWS dans webpack.config.js).
	 */
	public const LIGHT_VIEWS = [ 'overview' ];

	private Menu $menu;
	private Preferences $preferences;
	private string $build_dir;
	private string $build_url;

	public function __construct( Menu $menu, Preferences $preferences, string $build_dir, string $build_url ) {
		$this->menu        = $menu;
		$this->preferences = $preferences;
		$this->build_dir   = trailingslashit( $build_dir );
		$this->build_url   = trailingslashit( $build_url );
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * @param mixed $hook_suffix Suffixe de la page d'administration.
	 */
	public function enqueue( $hook_suffix ): void {
		$view = $this->menu->view_for_hook( (string) $hook_suffix );
		if ( null !== $view ) {
			$this->enqueue_view( $view );
		}
	}

	public function enqueue_view( string $view ): bool {
		$asset_file = $this->build_dir . 'admin/' . $view . '.asset.php';
		$shared     = ! in_array( $view, self::LIGHT_VIEWS, true );
		if ( ! is_readable( $asset_file ) || ( $shared && ! $this->register_shared_chunk() ) ) {
			add_action( 'network_admin_notices', [ $this, 'render_missing_build_notice' ] );
			return false;
		}
		$asset        = (array) require $asset_file;
		$handle       = 'msradar-' . $view;
		$version      = (string) ( $asset['version'] ?? MSRADAR_VERSION );
		$dependencies = (array) ( $asset['dependencies'] ?? [] );
		$style_deps   = [ 'wp-components' ];
		if ( $shared ) {
			$dependencies[] = self::SHARED_HANDLE;
			if ( wp_style_is( self::SHARED_HANDLE, 'registered' ) ) {
				$style_deps[] = self::SHARED_HANDLE;
				wp_enqueue_style( self::SHARED_HANDLE );
			}
		}

		wp_enqueue_script( $handle, $this->build_url . 'admin/' . $view . '.js', $dependencies, $version, true );
		wp_set_script_translations( $handle, 'multisite-radar', MSRADAR_DIR . 'languages' );
		wp_add_inline_script( $handle, self::SHARE_TRANSLATIONS, 'before' );
		wp_add_inline_script( $handle, 'window.msradarAdmin = ' . wp_json_encode( $this->config( $view ), JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );

		if ( is_readable( $this->build_dir . 'admin/' . $view . '.css' ) ) {
			wp_enqueue_style( $handle, $this->build_url . 'admin/' . $view . '.css', $style_deps, $version );
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}
		return true;
	}

	/**
	 * Enregistre le chunk commun (script, traductions, feuille de style) une seule fois par page.
	 *
	 * @return bool Faux si le build ne contient pas le chunk.
	 */
	private function register_shared_chunk(): bool {
		if ( wp_script_is( self::SHARED_HANDLE, 'registered' ) ) {
			return true;
		}
		$asset_file = $this->build_dir . 'admin/' . self::SHARED_CHUNK . '.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return false;
		}
		$asset   = (array) require $asset_file;
		$version = (string) ( $asset['version'] ?? MSRADAR_VERSION );

		wp_register_script( self::SHARED_HANDLE, $this->build_url . 'admin/' . self::SHARED_CHUNK . '.js', (array) ( $asset['dependencies'] ?? [] ), $version, true );
		// Les chaînes de DataViews sont dans ce fichier : bin/i18n.sh produit leur JSON sous son nom.
		wp_set_script_translations( self::SHARED_HANDLE, 'multisite-radar', MSRADAR_DIR . 'languages' );
		if ( is_readable( $this->build_dir . 'admin/' . self::SHARED_CHUNK . '.css' ) ) {
			wp_register_style( self::SHARED_HANDLE, $this->build_url . 'admin/' . self::SHARED_CHUNK . '.css', [ 'wp-components' ], $version );
			wp_style_add_data( self::SHARED_HANDLE, 'rtl', 'replace' );
		}
		return true;
	}

	public function config( string $view ): array {
		$pages = [];
		foreach ( array_keys( Menu::PAGES ) as $page ) {
			$pages[ $page ] = Menu::url( $page );
		}
		// Pas de sanitize_text_field() : il regrouperait les espaces et retirerait les « %xx » de la recherche, et la
		// requête préchargée ne serait plus celle du client. ViewQuery valide chaque paramètre (listes blanches, chiffres)
		// et la recherche ne sert qu'à une requête préparée.
		$query  = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only view state, validated by ViewQuery.
		$config = [
			'view'        => $view,
			'pages'       => $pages,
			'canManage'   => current_user_can( Capabilities::MANAGE ),
			'exportUrl'   => admin_url( 'admin-post.php' ),
			'exportNonce' => wp_create_nonce( ExportHandler::ACTION ),
			'preload'     => Preload::run( Preload::paths( $view, $query, $this->preferences->get( get_current_user_id() ) ) ),
		];
		if ( 'settings' === $view ) {
			$config['postTypes'] = self::post_types();
			$config['plugins']   = self::plugins();
		}
		return $config;
	}

	public function render_missing_build_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Multisite Radar: the interface files are missing. Run "npm install && npm run build" in the plugin folder, or install a release package.', 'multisite-radar' )
		);
	}

	/**
	 * Types publics du site principal, suggérés pour « types d'activité ».
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function post_types(): array {
		$types = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $name => $object ) {
			if ( 'attachment' !== $name ) {
				$types[] = [
					'value' => (string) $name,
					'label' => (string) $object->labels->name,
				];
			}
		}
		return $types;
	}

	/**
	 * Plugins installés, identifiés comme dans l'origine des types (dossier du plugin, ou nom du fichier seul).
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = [];
		foreach ( get_plugins() as $file => $data ) {
			$slug             = '.' === dirname( $file ) ? basename( $file, '.php' ) : dirname( $file );
			$plugins[ $slug ] = [
				'value' => $slug,
				'label' => (string) $data['Name'],
			];
		}
		return array_values( $plugins );
	}
}
