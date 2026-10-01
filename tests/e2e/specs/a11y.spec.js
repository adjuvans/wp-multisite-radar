import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

const PAGES = [
	'page=multisite-radar',
	'page=multisite-radar-sites',
	'page=multisite-radar-alerts',
	'page=multisite-radar-settings',
];

async function seriousViolations( page ) {
	const results = await new AxeBuilder( { page } )
		.include( '.msradar-wrap' )
		// Champ factice de @wordpress/dataviews (FormTokenField validé) : input texte invisible
		// (opacity 0, tabindex -1) sans libellé, signalé par la règle « label ». Hors de notre code ;
		// on n'exclut que ce nœud, jamais la règle.
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
} );
