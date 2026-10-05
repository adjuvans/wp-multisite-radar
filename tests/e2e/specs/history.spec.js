import { expect, test } from '@wordpress/e2e-test-utils-playwright';

// Ids that appear more than once on the page (an invalid DOM that breaks label associations).
async function duplicateIds( page ) {
	return page.evaluate( () => {
		const seen = new Set();
		const duplicates = new Set();
		document.querySelectorAll( '[id]' ).forEach( ( element ) => {
			if ( seen.has( element.id ) ) {
				duplicates.add( element.id );
			}
			seen.add( element.id );
		} );
		return [ ...duplicates ];
	} );
}

test.describe( 'History and reports', () => {
	test( 'the Reports page shows the trends, the changes and the digest settings', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-reports'
		);
		const app = page.locator( '#msradar-app' );

		await expect(
			app.getByRole( 'heading', { name: 'Trends' } )
		).toBeVisible();
		await expect(
			app.getByRole( 'heading', { name: "What's new" } )
		).toBeVisible();
		await expect(
			app.getByRole( 'combobox', { name: 'Kind of change' } )
		).toBeVisible();
		await expect(
			app.getByRole( 'checkbox', {
				name: /Send a weekly summary by e-mail/,
			} )
		).toBeVisible();

		expect( await duplicateIds( page ) ).toEqual( [] );
	} );

	test( 'a site sheet has a History tab', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-sites&s=Blog%20RH'
		);
		await page
			.locator( '#msradar-app' )
			.getByText( 'Blog RH', { exact: true } )
			.click();
		const panel = page.getByRole( 'dialog', { name: 'Blog RH' } );
		await panel.getByRole( 'tab', { name: 'History' } ).click();

		await expect(
			panel.getByRole( 'heading', { name: 'Changes' } )
		).toBeVisible();
		await expect(
			panel.getByRole( 'combobox', { name: 'Period' } )
		).toBeVisible();

		expect( await duplicateIds( page ) ).toEqual( [] );
	} );

	test( 'the overview shows the recent changes', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar'
		);

		await expect(
			page.getByRole( 'heading', { name: 'Recent changes' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'See all changes' } )
		).toHaveAttribute( 'href', /multisite-radar-reports/ );
	} );
} );
