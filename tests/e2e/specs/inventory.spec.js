import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Cross inventory (spec 7.1)', () => {
	test( 'a plugin active on one site leads to that site', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-plugins'
		);
		const app = page.locator( '#msradar-app' );

		await app
			.getByText( 'Multisite Radar demo CPT', { exact: true } )
			.click();
		const panel = page.getByRole( 'dialog', {
			name: 'Multisite Radar demo CPT',
		} );
		await expect( panel ).toBeVisible();
		await expect(
			panel.getByText( '1 site', { exact: true } )
		).toBeVisible();

		await panel.getByRole( 'link', { name: 'Blog RH' } ).click();
		await expect( page ).toHaveURL(
			/page=multisite-radar-sites.*site=\d+/
		);
		await expect(
			page.getByRole( 'dialog', { name: 'Blog RH' } )
		).toBeVisible();
	} );

	test( 'the unused plugins tile opens the filtered plugin list', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar'
		);

		await page.getByRole( 'link', { name: /Unused plugins/ } ).click();

		await expect( page ).toHaveURL(
			/page=multisite-radar-plugins.*status=unused/
		);
		const app = page.locator( '#msradar-app' );
		await expect(
			app.getByText( 'Hello Dolly', { exact: true } )
		).toBeVisible();
		await expect(
			app.getByText( 'Multisite Radar demo CPT', { exact: true } )
		).toHaveCount( 0 );
	} );

	test( 'the theme used by the network lists its sites', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-themes&status=used'
		);
		const theme = page
			.locator( '#msradar-app .msradar-site-title__name' )
			.first();
		const name = ( await theme.textContent() ).trim();

		await theme.click();

		const panel = page.getByRole( 'dialog', { name } );
		await expect(
			panel.getByRole( 'link', { name: 'Blog RH' } )
		).toBeVisible();
	} );

	test( 'the users page flags super admins and finds accounts without a site', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-users'
		);
		const app = page.locator( '#msradar-app' );
		await expect(
			app.getByRole( 'row', { name: /admin.*Super admin/ } )
		).toBeVisible();

		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-users&membership=none'
		);
		// Le login s'affiche deux fois : dans le titre de la ligne et dans la colonne « login ».
		await expect(
			app.getByText( 'radar-orphan', { exact: true } ).first()
		).toBeVisible();
		await expect( app.getByText( 'admin', { exact: true } ) ).toHaveCount(
			0
		);
	} );
} );
