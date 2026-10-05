import { expect, test } from '@wordpress/e2e-test-utils-playwright';

const ROW = 'tbody tr.dataviews-view-table__row';

/**
 * Clique sur la dernière cellule de données d'une ligne (avant la colonne d'actions), loin du titre.
 *
 * @param {import('@playwright/test').Locator} row Ligne du tableau.
 */
async function clickPlainCell( row ) {
	await row.locator( 'td' ).nth( -2 ).click();
}

test.describe( 'A click anywhere in a row opens the detail (rc.2 spec 2.1)', () => {
	for ( const [ page, label ] of [
		[ 'multisite-radar-sites', 'Sites' ],
		[ 'multisite-radar-plugins', 'Plugins' ],
		[ 'multisite-radar-themes', 'Themes' ],
		[ 'multisite-radar-alerts', 'Alerts' ],
	] ) {
		test( `on ${ label }`, async ( { admin, page: browser } ) => {
			await admin.visitAdminPage( 'network/admin.php', `page=${ page }` );
			const row = browser.locator( `#msradar-app ${ ROW }` ).first();
			await expect( row ).toBeVisible();
			const name = (
				await row
					.locator( '.msradar-site-title__name' )
					.first()
					.innerText()
			).trim();

			await clickPlainCell( row );

			await expect(
				browser.getByRole( 'dialog', { name } )
			).toBeVisible();
			await expect( browser ).toHaveURL( new RegExp( `page=${ page }` ) );
		} );
	}

	test( 'on Users, with the account panel', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-users'
		);
		const row = page
			.locator( `#msradar-app ${ ROW }` )
			.filter( { has: page.getByText( 'admin', { exact: true } ) } );

		await clickPlainCell( row );

		const panel = page.getByRole( 'dialog', { name: 'admin' } );
		await expect( panel ).toBeVisible();
		await expect( page ).toHaveURL( /user=1/ );
		await expect(
			panel.getByRole( 'link', { name: 'Edit the account' } )
		).toBeVisible();
		await expect(
			panel.locator( '.msradar-list li' ).first()
		).toBeVisible();
	} );
} );

test( 'the bulk actions bar appears above the Sites table (rc.2 spec 2.3)', async ( {
	admin,
	page,
} ) => {
	await admin.visitAdminPage(
		'network/admin.php',
		'page=multisite-radar-sites'
	);
	const row = page.locator( `#msradar-app ${ ROW }` ).first();
	await row.locator( 'input[type="checkbox"]' ).check();

	const bar = page.locator(
		'#msradar-app .msradar-dataviews__bulk .dataviews-bulk-actions-footer__container'
	);
	await expect( bar ).toBeVisible();
	const barBox = await bar.boundingBox();
	const tableBox = await page
		.locator( '#msradar-app .dataviews-view-table' )
		.boundingBox();
	expect( barBox.y ).toBeLessThan( tableBox.y );
	await expect(
		page.locator(
			'#msradar-app .dataviews-footer .dataviews-bulk-actions-footer__container'
		)
	).toHaveCount( 0 );
} );
