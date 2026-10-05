import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.use( { viewport: { width: 1440, height: 900 } } );

test( 'the Sites table fits a 1440 px wide screen: no column hides under the fixed Actions column', async ( {
	admin,
	page,
} ) => {
	await admin.visitAdminPage(
		'network/admin.php',
		'page=multisite-radar-sites'
	);
	const table = page.locator( '#msradar-app .dataviews-view-table' );
	await expect( table.locator( 'tbody tr' ).first() ).toBeVisible();

	const overflow = await page.evaluate( () => {
		const container = document.querySelector(
			'#msradar-app .dataviews-layout__container'
		);
		return container.scrollWidth - container.clientWidth;
	} );
	expect( overflow ).toBeLessThanOrEqual( 0 );

	const headers = table.locator( 'thead th' );
	const count = await headers.count();
	const actions = await headers.nth( count - 1 ).boundingBox();
	const last = await headers.nth( count - 2 ).boundingBox();
	expect( last.x + last.width ).toBeLessThanOrEqual( actions.x + 1 );
} );
