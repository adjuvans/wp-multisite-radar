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

	private function post_items( array $items, bool $valid_nonce = true ): void {
		$_POST['menu-settings-column-nonce'] = $valid_nonce ? wp_create_nonce( 'add-menu_item' ) : 'invalid';
		$_POST['menu']                       = 0;
		$_POST['menu-item']                  = $items;
	}

	private function site_item( int $site, int $key = -1 ): array {
		return [
			$key => [
				'menu-item-object-id' => (string) $site,
				'menu-item-object'    => NavMenu::TYPE,
				'menu-item-type'      => NavMenu::TYPE,
				'menu-item-title'     => 'Blog RH',
			],
		];
	}

	private function menu_item_count(): int {
		return count(
			get_posts(
				[
					'post_type'   => 'nav_menu_item',
					'post_status' => 'any',
					'numberposts' => -1,
				]
			)
		);
	}

	public function test_a_bad_nonce_is_rejected_and_creates_nothing(): void {
		$site = self::factory()->blog->create();
		$this->_setRole( 'administrator' );
		$this->post_items( $this->site_item( $site ), false );

		try {
			$this->_handleAjax( 'add-menu-item' );
			$this->fail( 'The request should have been rejected.' );
		} catch ( \WPAjaxDieStopException $e ) {
			$this->assertSame( '-1', $e->getMessage() );
		}
		$this->assertSame( 0, $this->menu_item_count() );
	}

	public function test_a_user_who_cannot_edit_theme_options_is_rejected_and_creates_nothing(): void {
		$site = self::factory()->blog->create();
		$this->_setRole( 'subscriber' );
		$this->post_items( $this->site_item( $site ) );

		try {
			$this->_handleAjax( 'add-menu-item' );
			$this->fail( 'The request should have been rejected.' );
		} catch ( \WPAjaxDieStopException $e ) {
			$this->assertSame( '-1', $e->getMessage() );
		}
		$this->assertSame( 0, $this->menu_item_count() );
	}

	public function test_requests_that_are_not_only_network_sites_are_left_to_the_core_handler(): void {
		$site = self::factory()->blog->create();
		$this->_setRole( 'administrator' );
		// Core's handler is removed so the test observes only what our handler does with a request that is not ours.
		remove_action( 'wp_ajax_add-menu-item', 'wp_ajax_add_menu_item', 1 );

		$custom = [
			'menu-item-type'  => 'custom',
			'menu-item-title' => 'Custom',
			'menu-item-url'   => 'https://example.org/',
		];
		$cases  = [
			'custom only' => [ -1 => $custom ],
			'mixed'       => $this->site_item( $site, -2 ) + [ -1 => $custom ],
		];
		foreach ( $cases as $label => $items ) {
			$this->post_items( $items );
			try {
				$this->_handleAjax( 'add-menu-item' );
			} catch ( \WPAjaxDieContinueException $e ) {
				unset( $e );
			}
			$this->assertSame( '', $this->_last_response, $label );
			$this->assertSame( 0, $this->menu_item_count(), $label );
		}
		add_action( 'wp_ajax_add-menu-item', 'wp_ajax_add_menu_item', 1 );
	}
}
