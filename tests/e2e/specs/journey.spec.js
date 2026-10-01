import { readFile } from 'fs/promises';
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Overview → filtered sites → site panel → export (spec 11.2)', () => {
	test( 'follows an alert from the overview to a CSV export', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar'
		);

		await page.getByRole( 'link', { name: 'Site without users' } ).click();
		await expect( page ).toHaveURL(
			/page=multisite-radar-sites.*rule=no_users/
		);
		await expect(
			page
				.locator( '#msradar-app' )
				.getByText( 'Site vide', { exact: true } )
		).toBeVisible();
		await expect(
			page
				.locator( '#msradar-app' )
				.getByText( 'Blog RH', { exact: true } )
		).toHaveCount( 0 );

		await page
			.locator( '#msradar-app' )
			.getByText( 'Site vide', { exact: true } )
			.click();
		const panel = page.getByRole( 'dialog', { name: 'Site vide' } );
		await expect( panel ).toBeVisible();
		await expect( page ).toHaveURL( /site=\d+/ );
		await panel.getByRole( 'tab', { name: 'Alerts' } ).click();
		await expect(
			panel.getByText( 'No user is attached to this site.' )
		).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( panel ).toBeHidden();

		const downloading = page.waitForEvent( 'download' );
		await page.getByRole( 'button', { name: 'Export' } ).click();
		await page.getByRole( 'menuitem', { name: 'Export as CSV' } ).click();
		const download = await downloading;
		expect( download.suggestedFilename() ).toMatch(
			/^multisite-radar-sites-\d{8}-\d{6}\.csv$/
		);
		const csv = ( await readFile( await download.path() ) ).toString(
			'utf8'
		);
		expect( csv.charCodeAt( 0 ) ).toBe( 0xfeff );
		expect( csv ).toContain( 'Site vide' );
		expect( csv ).not.toContain( 'Blog RH' );
	} );

	test( 'the content types of a site are read in the context of that site (spec 1.4, criterion 1)', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-sites&s=Blog%20RH'
		);

		await page
			.locator( '#msradar-app' )
			.getByText( 'Blog RH', { exact: true } )
			.click();
		const panel = page.getByRole( 'dialog', { name: 'Blog RH' } );
		await panel.getByRole( 'tab', { name: 'Content' } ).click();

		await expect( panel.getByText( 'Demo events' ) ).toBeVisible();
		await expect(
			panel.getByText( 'Plugin: msradar-demo-cpt' )
		).toBeVisible();
	} );
} );
