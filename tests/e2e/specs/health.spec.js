import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Advanced health (milestone M4)', () => {
	test( 'a site hidden from search engines raises an info alert', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-alerts'
		);
		const app = page.locator( '#msradar-app' );

		await expect(
			app.getByText( 'Hidden from search engines' ).first()
		).toBeVisible();
		await expect(
			app.getByText( 'Site discret', { exact: true } ).first()
		).toBeVisible();
	} );

	test( 'the site panel shows the measures of the last analysis', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-sites'
		);
		await page
			.locator( '#msradar-app' )
			.getByText( 'Blog RH', { exact: true } )
			.click();
		const panel = page.getByRole( 'dialog', { name: 'Blog RH' } );
		const fact = ( label ) =>
			panel
				.locator( 'dt', { hasText: label } )
				.locator( 'xpath=following-sibling::dd[1]' );

		await expect( fact( 'Database' ) ).toHaveText(
			/^\d+(\.\d+)? (KB|MB)$/
		);
		await expect( fact( 'Autoloaded options' ) ).toHaveText(
			/^\d+(\.\d+)? (B|KB|MB)$/
		);
		await expect( fact( 'Disk' ) ).not.toHaveText( '—' );
		await expect( fact( 'Scheduled tasks' ) ).not.toHaveText( '—' );
	} );

	test( 'a rule parameter is saved from the settings', async ( {
		admin,
		page,
	} ) => {
		const app = page.locator( '#msradar-app' );
		const saved = page
			.locator( '.components-snackbar' )
			.getByText( 'Settings saved.' );
		const open = async () => {
			await admin.visitAdminPage(
				'network/admin.php',
				'page=multisite-radar-settings'
			);
			await app.getByRole( 'button', { name: 'Inactive site' } ).click();
			return app.getByRole( 'spinbutton', {
				name: /Months without activity/,
			} );
		};

		let months = await open();
		await expect( months ).toHaveValue( '6' );
		await months.fill( '9' );
		await app.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( saved ).toBeVisible();

		months = await open();
		await expect( months ).toHaveValue( '9' );

		// Remet la valeur par défaut : le test reste rejouable.
		await months.fill( '6' );
		await app.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( saved ).toBeVisible();
	} );
} );
