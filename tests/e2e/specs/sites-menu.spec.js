import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Network sites menu module', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/multisite-radar/v1/settings',
			data: { sites_menu: { enabled: true } },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.rest( {
			method: 'POST',
			path: '/multisite-radar/v1/settings',
			data: { sites_menu: { enabled: false } },
		} );
	} );

	test( 'the block and the shortcode list the public sites', async ( {
		requestUtils,
		page,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Our sites',
			status: 'publish',
			content:
				'<!-- wp:multisite-radar/sites-list {"layout":"inline"} /-->\n<!-- wp:shortcode -->[msradar_sites class="shortcode-list"]<!-- /wp:shortcode -->',
		} );

		await page.goto( post.link );

		const block = page.locator( '.wp-block-multisite-radar-sites-list' );
		await expect(
			block.getByRole( 'link', { name: 'Blog RH' } )
		).toBeVisible();
		await expect( block ).toHaveClass( /msradar-sites--inline/ );
		await expect(
			page
				.locator( 'ul.shortcode-list' )
				.getByRole( 'link', { name: 'Site vide' } )
		).toBeVisible();
	} );
} );
