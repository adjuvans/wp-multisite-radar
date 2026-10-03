import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Integrations settings', () => {
	test( 'the MCP exposure is saved and stays on after a reload', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const app = page.locator( '#msradar-app' );
		const toggle = () =>
			app.getByRole( 'checkbox', {
				name: /Let AI assistants read the audit through MCP/,
			} );
		const open = () =>
			admin.visitAdminPage(
				'network/admin.php',
				'page=multisite-radar-settings'
			);

		try {
			await open();
			await expect( toggle() ).not.toBeChecked();
			await toggle().check();
			await app.getByRole( 'button', { name: 'Save settings' } ).click();
			await expect(
				page
					.locator( '.components-snackbar' )
					.getByText( 'Settings saved.' )
			).toBeVisible();

			await open();
			await expect( toggle() ).toBeChecked();
		} finally {
			// Remet la valeur par défaut, même si une assertion a échoué : le test reste rejouable.
			await requestUtils.rest( {
				method: 'POST',
				path: '/multisite-radar/v1/settings',
				data: { integrations: { mcp_public: false } },
			} );
		}
	} );
} );
