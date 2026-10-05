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

	test( 'the block settings find a site by name and keep its name once chosen', async ( {
		admin,
		editor,
		page,
	} ) => {
		const requests = [];
		page.on( 'request', ( request ) => {
			if ( request.url().includes( 'sites-menu' ) ) {
				requests.push( request.url() );
			}
		} );
		await admin.createNewPost();
		await editor.insertBlock( { name: 'multisite-radar/sites-list' } );
		await editor.openDocumentSettingsSidebar();

		const only = page.getByRole( 'combobox', { name: 'Only these sites' } );
		await only.fill( 'Blog' );
		const option = page.getByRole( 'option', {
			name: /^Blog RH \(#\d+\)$/,
		} );
		await expect( option ).toBeVisible();
		await option.click();

		await expect(
			page.locator( '.components-form-token-field__token-text', {
				hasText: /Blog RH \(#\d+\)/,
			} )
		).toBeVisible();
		await expect(
			editor.canvas
				.locator( '.wp-block-multisite-radar-sites-list' )
				.getByRole( 'link', { name: 'Blog RH' } )
		).toBeVisible();
		// Une recherche par saisie, pas une par rendu : le nombre de requêtes reste petit.
		expect( requests.length ).toBeLessThan( 10 );
	} );
} );
