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

test( 'with every Sites column shown, no column stays under the Actions column', async ( {
	admin,
	page,
	requestUtils,
} ) => {
	const fields = [
		'theme',
		'users_count',
		'content_count',
		'media_count',
		'disk_bytes',
		'db_bytes',
		'last_activity_gmt',
		'scanned_at_gmt',
		'alert_level',
		'rule',
		'status',
		'registry_status',
	];
	await requestUtils.rest( {
		method: 'POST',
		path: '/multisite-radar/v1/preferences',
		data: { sites: { fields } },
	} );
	try {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-sites'
		);
		const table = page.locator( '#msradar-app .dataviews-view-table' );
		await expect( table.locator( 'tbody tr' ).first() ).toBeVisible();
		await page.evaluate( () => {
			const container = document.querySelector(
				'#msradar-app .dataviews-layout__container'
			);
			container.scrollLeft = container.scrollWidth;
		} );

		const headers = table.locator( 'thead th' );
		const count = await headers.count();
		const actions = await headers.nth( count - 1 ).boundingBox();
		const last = await headers.nth( count - 2 ).boundingBox();
		expect( last.x + last.width ).toBeLessThanOrEqual( actions.x + 1 );
		// La colonne porte l'en-tête « Actions » de DataViews (environ 90 px) et le menu « ⋮ ».
		expect( actions.width ).toBeLessThan( 110 );
	} finally {
		await requestUtils.rest( {
			method: 'POST',
			path: '/multisite-radar/v1/preferences',
			data: { sites: { fields: [] } },
		} );
	}
} );
