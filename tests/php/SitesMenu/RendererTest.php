<?php
namespace MultisiteRadar\Tests\SitesMenu;

use MultisiteRadar\SitesMenu\Renderer;
use MultisiteRadar\Tests\TestCase;

final class RendererTest extends TestCase {

	private const SITES = [
		[
			'id'         => 3,
			'name'       => 'Émile',
			'url'        => 'https://c.test/',
			'registered' => '2026-01-03 00:00:00',
		],
		[
			'id'         => 1,
			'name'       => 'alpha',
			'url'        => 'https://a.test/',
			'registered' => '2026-01-02 00:00:00',
		],
		[
			'id'         => 2,
			'name'       => '',
			'url'        => 'https://b.test/',
			'registered' => '2026-01-01 00:00:00',
		],
	];

	private function ids( array $sites ): array {
		return wp_list_pluck( $sites, 'id' );
	}

	public function test_select_filters_and_sorts_ignoring_case_and_accents(): void {
		$this->assertSame( [ 1, 3, 2 ], $this->ids( Renderer::select( self::SITES, [], [], 'name', 'asc' ) ), 'alpha, Émile, then "Site #2".' );
		$this->assertSame( [ 3, 2, 1 ], $this->ids( Renderer::select( self::SITES, [], [], 'id', 'desc' ) ) );
		$this->assertSame( [ 2, 1, 3 ], $this->ids( Renderer::select( self::SITES, [], [], 'registered', 'asc' ) ) );
		$this->assertSame( [ 1, 3 ], $this->ids( Renderer::select( self::SITES, [ 3, 1 ], [], 'name', 'asc' ) ) );
		$this->assertSame( [ 3, 2 ], $this->ids( Renderer::select( self::SITES, [], [ 1 ], 'name', 'asc' ) ) );
		$this->assertSame( [ 1, 3, 2 ], $this->ids( Renderer::select( self::SITES, [], [], 'bogus', 'asc' ) ), 'Unknown order falls back to name.' );
	}

	public function test_render_escapes_marks_the_current_site_and_restricts_the_wrapper(): void {
		$sites = [
			[
				'id'         => get_current_blog_id(),
				'name'       => '<b>Main</b>',
				'url'        => 'javascript:alert(1)',
				'registered' => '',
			],
			[
				'id'         => 9,
				'name'       => 'Other',
				'url'        => 'https://o.test/',
				'registered' => '',
			],
		];

		$html = Renderer::render( $sites, 'script', 'class="x"' );

		$this->assertStringStartsWith( '<ul class="x">', $html );
		$this->assertStringEndsWith( '</ul>', $html );
		$this->assertStringContainsString( '&lt;b&gt;Main&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		$this->assertStringContainsString( '<a href="https://o.test/">Other</a>', $html );
		$this->assertStringStartsWith( '<ol class="x">', Renderer::render( $sites, 'ol', 'class="x"' ) );
	}
}
