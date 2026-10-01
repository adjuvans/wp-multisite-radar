<?php
namespace MultisiteRadar\Tests\SitesMenu;

use MultisiteRadar\SitesMenu\Block;
use MultisiteRadar\Tests\TestCase;
use WP_Block_Type_Registry;

final class BlockTest extends TestCase {

	private Block $block;

	public function set_up(): void {
		parent::set_up();
		if ( WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) ) {
			unregister_block_type( Block::NAME );
		}
		$this->block = new Block( $this->plugin()->sites_list_cache(), dirname( __DIR__ ) . '/fixtures/build/blocks/sites-list/' );
		$this->assertTrue( $this->block->register() );
	}

	public function tear_down(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) ) {
			unregister_block_type( Block::NAME );
		}
		parent::tear_down();
	}

	private function render( array $attrs ): string {
		return render_block(
			[
				'blockName'    => Block::NAME,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	private function classes( string $html ): array {
		$this->assertSame( 1, preg_match( '/^<ul class="([^"]*)">/', $html, $match ) );
		return explode( ' ', $match[1] );
	}

	public function test_renders_the_chosen_sites_by_name_with_the_block_wrapper(): void {
		$bravo = self::factory()->blog->create( [ 'title' => 'Bravo' ] );
		$alpha = self::factory()->blog->create( [ 'title' => 'Alpha' ] );

		$html = $this->render( [ 'include' => [ $bravo, $alpha ] ] );

		$classes = $this->classes( $html );
		$this->assertContains( 'wp-block-multisite-radar-sites-list', $classes );
		$this->assertContains( 'msradar-sites--list', $classes );
		$this->assertLessThan( strpos( $html, 'Bravo' ), strpos( $html, 'Alpha' ), 'Sorted by name by default.' );
	}

	public function test_site_names_are_escaped_exactly_once(): void {
		$site_id = self::factory()->blog->create( [ 'title' => "L'atelier R&D" ] );

		$html = $this->render( [ 'include' => [ $site_id ] ] );

		$this->assertStringContainsString( '>L&#039;atelier R&amp;D</a>', $html );
		$this->assertStringNotContainsString( '&amp;#039;', $html );
		$this->assertStringNotContainsString( '&amp;amp;', $html );
	}

	public function test_attributes_exclude_order_and_lay_out_the_list(): void {
		$bravo = self::factory()->blog->create( [ 'title' => 'Bravo' ] );
		$alpha = self::factory()->blog->create( [ 'title' => 'Alpha' ] );
		$third = self::factory()->blog->create( [ 'title' => 'Charlie' ] );

		$html = $this->render(
			[
				'include' => [ $bravo, $alpha, $third ],
				'exclude' => [ $alpha ],
				'orderBy' => 'id',
				'order'   => 'desc',
				'layout'  => 'inline',
			]
		);

		$this->assertContains( 'msradar-sites--inline', $this->classes( $html ) );
		$this->assertStringNotContainsString( 'Alpha', $html );
		$this->assertLessThan( strpos( $html, 'Bravo' ), strpos( $html, 'Charlie' ), 'Highest ID first.' );
	}

	public function test_nothing_is_rendered_without_matching_sites(): void {
		$this->assertSame( '', $this->render( [ 'include' => [ 999999 ] ] ) );
	}

	public function test_registration_needs_the_build_and_happens_once(): void {
		$this->assertFalse( $this->block->register(), 'Already registered.' );

		unregister_block_type( Block::NAME );
		$this->assertFalse( ( new Block( $this->plugin()->sites_list_cache(), '/nonexistent/' ) )->register() );
		$this->assertFalse( WP_Block_Type_Registry::get_instance()->is_registered( Block::NAME ) );
	}
}
