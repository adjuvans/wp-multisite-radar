/**
 * Captures de WordPress.org (readme.txt, section Screenshots), sur le réseau de démonstration de seed.sh :
 * npm run screenshots:seed, puis npm run screenshots. Écrit .wordpress-org/screenshot-<n>.png.
 */
import path from 'path';
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

const OUT = path.join( process.cwd(), '.wordpress-org' );

test.use( { viewport: { width: 1440, height: 900 } } );
test.describe.configure( { mode: 'serial' } );

async function shoot( page, number, fullPage = false ) {
	// Les avis de WordPress (mise à jour disponible) et la version du plugin n'ont pas leur place dans les captures :
	// elles ne doivent pas vieillir à chaque publication.
	await page.addStyleTag( {
		content:
			'#wpbody-content .notice, #wpbody-content .update-nag, .msradar-version, #footer-upgrade { display: none !important; }',
	} );
	await page.mouse.move( 0, 0 );
	await page.screenshot( {
		path: path.join( OUT, `screenshot-${ number }.png` ),
		animations: 'disabled',
		caret: 'hide',
		fullPage,
	} );
}

async function open( admin, page, query ) {
	await admin.visitAdminPage( 'network/admin.php', query );
	await expect( page.locator( '#msradar-app' ) ).not.toBeEmpty();
	await expect(
		page.locator( '#msradar-app .msradar-skeleton' )
	).toHaveCount( 0 );
}

test( '1. Overview', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar' );
	await expect(
		page.locator( '.msradar-alerts-trend svg.msradar-trend__chart' )
	).toBeVisible();
	await shoot( page, 1, true );
} );

test( '2. Sites', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar-sites' );
	await expect(
		page.locator( '#msradar-app .dataviews-view-table tbody tr' )
	).toHaveCount( 10 );
	await shoot( page, 2 );
} );

test( '3. Side panel of a site, Content tab', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar-sites' );
	await page
		.locator( '#msradar-app' )
		.getByText( 'Events 2026', { exact: true } )
		.click();
	const dialog = page.getByRole( 'dialog' );
	await dialog.getByRole( 'tab', { name: 'Content' } ).click();
	await expect( dialog.getByText( 'Demo events' ) ).toBeVisible();
	await shoot( page, 3 );
} );

test( '4. Alerts', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar-alerts' );
	await expect(
		page.locator( '#msradar-app .dataviews-view-table tbody tr' ).first()
	).toBeVisible();
	await shoot( page, 4 );
} );

test( '5. Plugins', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar-plugins' );
	await expect(
		page.locator( '#msradar-app' ).getByText( 'Multisite Radar demo CPT' )
	).toBeVisible();
	await shoot( page, 5 );
} );

test( '6. Reports', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar-reports' );
	await expect(
		page.locator( '.msradar-reports__trends svg.msradar-trend__chart' )
	).toHaveCount( 3 );
	await shoot( page, 6, true );
} );

test( '7. Settings, an alert rule', async ( { admin, page } ) => {
	await open( admin, page, 'page=multisite-radar-settings' );
	await page
		.locator( '#msradar-app' )
		.getByRole( 'button', { name: 'Inactive site' } )
		.click();
	const months = page.getByRole( 'spinbutton', {
		name: /Months without activity/,
	} );
	await expect( months ).toBeVisible();
	await months.scrollIntoViewIfNeeded();
	await shoot( page, 7 );
} );
