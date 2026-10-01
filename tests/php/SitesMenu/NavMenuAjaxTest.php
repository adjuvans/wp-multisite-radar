<?php
namespace MultisiteRadar\Tests\SitesMenu;

use MultisiteRadar\Plugin;
use MultisiteRadar\SitesMenu\NavMenu;
use WPAjaxDieContinueException;

/**
 * Ajout d'un élément depuis la metabox (admin-ajax.php?action=add-menu-item).
 */
final class NavMenuAjaxTest extends \WP_Ajax_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Plugin::instance()->sites_menu()->nav_menu()->register_editor();
	}

	public function tear_down(): void {
		remove_action( 'wp_ajax_add-menu-item', [ Plugin::instance()->sites_menu()->nav_menu(), 'ajax_add_menu_item' ], 0 );
		parent::tear_down();
	}

	public function test_adds_network_site_items_without_reaching_the_core_handler(): void {
		$site = self::factory()->blog->create( [ 'title' => 'Blog RH' ] );
		$this->_setRole( 'administrator' );
		$_POST['menu-settings-column-nonce'] = wp_create_nonce( 'add-menu_item' );
		$_POST['menu']                       = 0;
		$_POST['menu-item']                  = [
			-1 => [
				'menu-item-object-id' => (string) $site,
				'menu-item-object'    => NavMenu::TYPE,
				'menu-item-type'      => NavMenu::TYPE,
				'menu-item-title'     => 'Blog RH',
				'menu-item-url'       => 'https://ignored.test/',
			],
		];

		try {
			$this->_handleAjax( 'add-menu-item' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		// Le gestionnaire du cœur aurait émis des avertissements PHP (variable non définie), que PHPUnit transforme en échec.
		$this->assertStringContainsString( 'Blog RH', $this->_last_response );
		$this->assertStringContainsString( 'Network site', $this->_last_response );
		$items = get_posts(
			[
				'post_type'   => 'nav_menu_item',
				'post_status' => 'draft',
				'numberposts' => -1,
			]
		);
		$this->assertCount( 1, $items );
		$this->assertSame( NavMenu::TYPE, get_post_meta( $items[0]->ID, '_menu_item_type', true ) );
		$this->assertSame( (string) $site, (string) get_post_meta( $items[0]->ID, '_menu_item_object_id', true ) );
	}
}
