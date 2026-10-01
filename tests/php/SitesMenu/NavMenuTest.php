<?php
namespace MultisiteRadar\Tests\SitesMenu;

use MultisiteRadar\SitesMenu\NavMenu;
use MultisiteRadar\Tests\TestCase;

final class NavMenuTest extends TestCase {

	private function add_item( int $menu, int $site_id, string $title, int $item = 0 ): int {
		return (int) wp_update_nav_menu_item(
			$menu,
			$item,
			[
				'menu-item-type'      => NavMenu::TYPE,
				'menu-item-object'    => NavMenu::TYPE,
				'menu-item-object-id' => $site_id,
				'menu-item-title'     => $title,
				'menu-item-status'    => 'publish',
			]
		);
	}

	public function test_items_follow_their_site_keep_a_custom_title_and_are_invalid_once_the_site_is_not_public(): void {
		$site = self::factory()->blog->create( [ 'title' => 'Blog RH' ] );
		$menu = wp_create_nav_menu( 'Main' );
		$item = $this->add_item( $menu, $site, '' );

		$setup = wp_setup_nav_menu_item( get_post( $item ) );
		$this->assertSame( get_blog_option( $site, 'home' ), $setup->url );
		$this->assertSame( 'Blog RH', $setup->title );
		$this->assertSame( 'Network site', $setup->type_label );
		$this->assertEmpty( $setup->_invalid ?? false );

		$this->add_item( $menu, $site, 'Human resources', $item );
		$this->assertSame( 'Human resources', wp_setup_nav_menu_item( get_post( $item ) )->title );

		update_blog_status( $site, 'archived', '1' );
		$this->assertTrue( wp_setup_nav_menu_item( get_post( $item ) )->_invalid );
	}

	public function test_a_migrated_1x_item_pointing_to_a_deleted_site_is_invalid(): void {
		$menu = wp_create_nav_menu( 'Legacy' );
		$item = $this->add_item( $menu, 999999, 'Gone' );

		$this->assertTrue( wp_setup_nav_menu_item( get_post( $item ) )->_invalid );
	}

	public function test_the_metabox_lists_public_sites_as_menu_item_checkboxes(): void {
		$site = self::factory()->blog->create( [ 'title' => 'Blog <RH>' ] );

		ob_start();
		$this->plugin()->sites_menu()->nav_menu()->render_metabox();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="submit-posttype-msradar-sites"', $html );
		$this->assertStringContainsString( 'class="tabs-panel tabs-panel-active"', $html );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" class="menu-item-checkbox" name="menu-item\[-\d+\]\[menu-item-object-id\]" value="' . $site . '" \/> Blog &lt;RH&gt;/', $html );
		$this->assertStringContainsString( 'value="msradar_site"', $html );
	}
}
