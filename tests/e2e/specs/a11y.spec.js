import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

const PAGES = [
	'page=multisite-radar',
	'page=multisite-radar-sites',
	'page=multisite-radar-plugins',
	'page=multisite-radar-themes',
	'page=multisite-radar-users',
	'page=multisite-radar-alerts',
	'page=multisite-radar-reports',
	'page=multisite-radar-settings',
];

async function seriousViolations( page ) {
	const results = await new AxeBuilder( { page } )
		.include( '.msradar-wrap' )
		// @wordpress/dataviews 19.1 ajoute à un FormTokenField validé (page Réglages) un champ texte invisible
		// (opacity 0, tabindex -1) sans libellé, que la règle « label » signale. Ce nœud n'est pas dans notre code :
		// on n'exclut que lui, sur toutes les pages auditées, jamais la règle. À revoir à chaque montée de version de
		// DataViews (spec §14).
		.exclude( '.dataviews-validated-control__error-delegate' )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	return results.violations
		.filter( ( violation ) =>
			[ 'serious', 'critical' ].includes( violation.impact )
		)
		.map(
			( violation ) =>
				`${ violation.id }: ${ violation.nodes.map( ( node ) => node.target.join( ' ' ) ).join( ', ' ) }`
		);
}

test.describe( 'Accessibility, WCAG 2.2 AA (spec 1.4, criterion 5)', () => {
	for ( const query of PAGES ) {
		test( `no serious or critical violation on ${ query }`, async ( {
			admin,
			page,
		} ) => {
			await admin.visitAdminPage( 'network/admin.php', query );
			await expect( page.locator( '#msradar-app' ) ).not.toBeEmpty();

			expect( await seriousViolations( page ) ).toEqual( [] );
		} );
	}

	test( 'no serious or critical violation with the site panel open', async ( {
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
		await expect( page.getByRole( 'dialog' ) ).toBeVisible();

		expect( await seriousViolations( page ) ).toEqual( [] );
	} );

	test( 'no serious or critical violation with the sites of a plugin open', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-plugins'
		);
		await page
			.locator( '#msradar-app' )
			.getByText( 'Multisite Radar demo CPT', { exact: true } )
			.click();
		await expect( page.getByRole( 'dialog' ) ).toBeVisible();

		expect( await seriousViolations( page ) ).toEqual( [] );
	} );

	test( 'no serious or critical violation with an alert rule open in the settings', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/admin.php',
			'page=multisite-radar-settings'
		);
		await page
			.locator( '#msradar-app' )
			.getByRole( 'button', { name: 'Inactive site' } )
			.click();
		await expect(
			page.getByRole( 'spinbutton', { name: /Months without activity/ } )
		).toBeVisible();

		expect( await seriousViolations( page ) ).toEqual( [] );
	} );
} );
