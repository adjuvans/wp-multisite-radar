<?php
namespace MultisiteRadar\SitesMenu;

use MultisiteRadar\Install\LegacyMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Éléments de menu « Network site » (type msradar_site) : metabox dans Apparence › Menus, URL calculée à partir du site
 * (verrouillée), titre personnalisable, élément invalide (masqué aux visiteurs) quand le site n'est plus public.
 */
final class NavMenu {

	public const TYPE = LegacyMigration::MENU_TYPE;

	private SitesListCache $cache;

	public function __construct( SitesListCache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Toujours actif : les éléments existants restent justes même si le module est désactivé ensuite.
	 */
	public function register_items(): void {
		add_filter( 'wp_setup_nav_menu_item', [ $this, 'hydrate' ] );
		add_action( 'wp_nav_menu_item_custom_fields', [ $this, 'render_item_note' ], 10, 2 );
	}

	/**
	 * Seulement quand le module est actif.
	 */
	public function register_editor(): void {
		add_action( 'load-nav-menus.php', [ $this, 'add_metabox' ] );
		// Avant le gestionnaire du cœur, branché en priorité 1.
		add_action( 'wp_ajax_add-menu-item', [ $this, 'ajax_add_menu_item' ], 0 );
	}

	public function add_metabox(): void {
		add_meta_box( 'msradar-sites', __( 'Network sites', 'multisite-radar' ), [ $this, 'render_metabox' ], 'nav-menus', 'side', 'default' );
	}

	/**
	 * Même balisage que les metabox du cœur : nav-menu.js ajoute les éléments cochés de « .tabs-panel-active .categorychecklist »
	 * avec le bouton dont l'identifiant vaut « submit-<id du conteneur> ».
	 */
	public function render_metabox(): void {
		$sites = Renderer::select( $this->cache->get(), [], [], 'name', 'asc' );
		echo '<div id="posttype-msradar-sites" class="posttypediv"><div id="tabs-panel-msradar-sites" class="tabs-panel tabs-panel-active">';
		if ( [] === $sites ) {
			echo '<p>' . esc_html__( 'No public site in this network.', 'multisite-radar' ) . '</p>';
		} else {
			echo '<ul id="msradar-sites-checklist" class="categorychecklist form-no-clear">';
			$index = 0;
			foreach ( $sites as $site ) {
				--$index;
				$label = Renderer::label( $site );
				printf(
					'<li><label class="menu-item-title"><input type="checkbox" class="menu-item-checkbox" name="%1$s[menu-item-object-id]" value="%2$d" /> %3$s</label>'
					. '<input type="hidden" class="menu-item-type" name="%1$s[menu-item-type]" value="%4$s" />'
					. '<input type="hidden" class="menu-item-object" name="%1$s[menu-item-object]" value="%4$s" />'
					. '<input type="hidden" class="menu-item-title" name="%1$s[menu-item-title]" value="%5$s" />'
					. '<input type="hidden" class="menu-item-url" name="%1$s[menu-item-url]" value="%6$s" /></li>',
					esc_attr( 'menu-item[' . $index . ']' ),
					(int) $site['id'],
					esc_html( $label ),
					esc_attr( self::TYPE ),
					esc_attr( $label ),
					esc_url( (string) $site['url'] )
				);
			}
			echo '</ul>';
		}
		echo '</div>';
		printf(
			'<p class="button-controls wp-clearfix"><span class="add-to-menu"><input type="submit"%1$s class="button submit-add-to-menu right" value="%2$s" name="add-msradar-sites-menu-item" id="submit-posttype-msradar-sites" /><span class="spinner"></span></span></p>',
			disabled( [] === $sites, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- disabled() returns a fixed attribute.
			esc_attr__( 'Add to Menu', 'multisite-radar' )
		);
		echo '</div>';
	}

	/**
	 * @param mixed $item Élément préparé par wp_setup_nav_menu_item().
	 * @return mixed
	 */
	public function hydrate( $item ) {
		if ( ! is_object( $item ) || self::TYPE !== ( $item->type ?? '' ) ) {
			return $item;
		}
		$item->type_label = __( 'Network site', 'multisite-radar' );
		$site             = $this->site( (int) ( $item->object_id ?? 0 ) );
		if ( null === $site ) {
			$item->_invalid = true;
			return $item;
		}
		$item->url = (string) $site['url'];
		if ( '' === trim( (string) ( $item->title ?? '' ) ) ) {
			// Le titre d'un élément de menu est du HTML (Walker_Nav_Menu l'affiche tel quel) ; le nom est du texte brut.
			$item->title = esc_html( Renderer::label( $site ) );
		}
		return $item;
	}

	/**
	 * @param mixed $item_id Identifiant de l'élément.
	 * @param mixed $item    Élément de menu.
	 */
	public function render_item_note( $item_id, $item ): void {
		if ( ! is_object( $item ) || self::TYPE !== ( $item->type ?? '' ) ) {
			return;
		}
		$text = ! empty( $item->_invalid )
			? __( 'This network site is no longer public: the item is hidden from visitors.', 'multisite-radar' )
			: __( 'Links to a network site: the address follows the site and cannot be edited; the label can.', 'multisite-radar' );
		printf( '<p class="description description-wide">%s</p>', esc_html( $text ) );
	}

	/**
	 * Ajout depuis la metabox. Le gestionnaire du cœur (wp_ajax_add_menu_item) ne connaît que les types post_type,
	 * post_type_archive et taxonomy : avec un autre type, il lit une variable non définie et émet des avertissements PHP.
	 * Les requêtes qui ne contiennent que nos éléments sont traitées ici, avec la même réponse ; les autres lui sont laissées.
	 */
	public function ajax_add_menu_item(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked below, once the request is known to be ours.
		$items = isset( $_POST['menu-item'] ) && is_array( $_POST['menu-item'] ) ? wp_unslash( $_POST['menu-item'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
		$menu  = isset( $_POST['menu'] ) ? absint( $_POST['menu'] ) : 0;
		// phpcs:enable
		if ( [] === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || self::TYPE !== ( $item['menu-item-type'] ?? '' ) ) {
				return;
			}
		}

		check_ajax_referer( 'add-menu_item', 'menu-settings-column-nonce' );
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( '-1' );
		}
		require_once ABSPATH . 'wp-admin/includes/nav-menu.php';

		$data = [];
		foreach ( $items as $item ) {
			$site = $this->site( absint( $item['menu-item-object-id'] ?? 0 ) );
			if ( null === $site ) {
				continue;
			}
			$data[] = [
				'menu-item-object-id' => (int) $site['id'],
				'menu-item-object'    => self::TYPE,
				'menu-item-type'      => self::TYPE,
				'menu-item-title'     => sanitize_text_field( (string) ( $item['menu-item-title'] ?? Renderer::label( $site ) ) ),
				'menu-item-url'       => esc_url_raw( (string) $site['url'] ),
			];
		}

		$item_ids   = wp_save_nav_menu_items( 0, $data );
		$menu_items = [];
		foreach ( $item_ids as $menu_item_id ) {
			$menu_object = get_post( $menu_item_id );
			if ( ! empty( $menu_object->ID ) ) {
				$menu_object        = wp_setup_nav_menu_item( $menu_object );
				$menu_object->label = $menu_object->title;
				$menu_items[]       = $menu_object;
			}
		}

		$walker_class_name = apply_filters( 'wp_edit_nav_menu_walker', 'Walker_Nav_Menu_Edit', $menu ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, applied as core does.
		if ( ! class_exists( $walker_class_name ) ) {
			wp_die( '0' );
		}
		if ( [] !== $menu_items ) {
			echo walk_nav_menu_tree( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core walker output.
				$menu_items,
				0,
				(object) [
					'after'       => '',
					'before'      => '',
					'link_after'  => '',
					'link_before' => '',
					'walker'      => new $walker_class_name(),
				]
			);
		}
		wp_die();
	}

	private function site( int $site_id ): ?array {
		foreach ( $this->cache->get() as $site ) {
			if ( (int) $site['id'] === $site_id ) {
				return $site;
			}
		}
		return null;
	}
}
