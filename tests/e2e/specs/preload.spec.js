import { expect, test } from '@wordpress/e2e-test-utils-playwright';

const PAGES = [
	[ 'multisite-radar', 'To review' ],
	[ 'multisite-radar-sites', 'Blog RH' ],
	// Recherche avec apostrophe : la clé préchargée par PHP doit être celle que demande le client.
	[ "multisite-radar-sites&s=L'atelier", "L'atelier R&D" ],
	[ 'multisite-radar-alerts', 'Site vide' ],
	[ 'multisite-radar-settings', 'Save settings' ],
];

test( 'the first view of each page is built from preloaded data (spec 1.4, criterion 2)', async ( {
	admin,
	page,
} ) => {
	for ( const [ slug, text ] of PAGES ) {
		const requests = [];
		const listener = ( request ) =>
			requests.push( decodeURIComponent( request.url() ) );
		page.on( 'request', listener );
		await admin.visitAdminPage( 'network/admin.php', `page=${ slug }` );
		await expect(
			page
				.locator( '#msradar-app' )
				.getByText( text, { exact: true } )
				.first()
		).toBeVisible();
		// Laisse partir d'éventuelles requêtes tardives : c'est justement ce qu'on vérifie.
		// eslint-disable-next-line playwright/no-networkidle
		await page.waitForLoadState( 'networkidle' );
		page.off( 'request', listener );

		expect(
			requests.filter( ( url ) =>
				url.includes( '/multisite-radar/v1/' )
			),
			slug
		).toEqual( [] );
	}
} );
