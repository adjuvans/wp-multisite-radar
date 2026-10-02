import { beforeEach, expect, test, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import { getSitesActions } from '../actions';
import { buildPath } from '../../../store/paths';
import SitesView from '..';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => new Promise( () => {} ) ),
} ) );

const PREFS = {
	sites: { fields: [], layout: 'table', per_page: 20 },
	alerts: { fields: [], per_page: 20 },
};

function site( id, name, level = 'none' ) {
	return {
		id,
		name,
		url: `https://example.test/${ id }/`,
		admin_url: `https://example.test/${ id }/wp-admin/`,
		status: { public: true, archived: false, spam: false, deleted: false },
		theme: { stylesheet: 'twentytwentyfive', template: 'twentytwentyfive' },
		users_count: level === 'none' ? 1 : 0,
		admins_count: level === 'none' ? 1 : 0,
		content_count: 3,
		media_count: 0,
		disk_bytes: null,
		db_bytes: null,
		autoload_bytes: null,
		last_activity_gmt: '2026-09-01T10:00:00',
		alert_level: level,
		alerts_count: level === 'none' ? 0 : 1,
		alert_rules: level === 'none' ? [] : [ 'no_users' ],
		registry_status: 'fresh',
		pending: false,
		dirty: false,
		scanned_at_gmt: '2026-09-02T10:00:00',
	};
}

async function settle() {
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
}

function renderView( extraPreload = {} ) {
	const preload = {
		...extraPreload,
		'/multisite-radar/v1/preferences': { body: PREFS, headers: {} },
		'/multisite-radar/v1/alerts/summary': {
			body: {
				total_sites: 2,
				scanned_sites: 2,
				pending_sites: 0,
				sites_with_alerts: 1,
				by_severity: { error: 1, warning: 0, info: 0 },
				by_rule: [
					{
						rule: 'no_users',
						label: 'Site without users',
						severity: 'error',
						enabled: true,
						count: 1,
					},
				],
			},
			headers: {},
		},
		'/multisite-radar/v1/sites?order=asc&orderby=name&page=1&per_page=20': {
			body: [ site( 1, 'Blog RH' ), site( 2, 'Site vide', 'error' ) ],
			headers: { 'X-WP-Total': '2', 'X-WP-TotalPages': '1' },
		},
	};
	window.msradarAdmin = {
		view: 'sites',
		canManage: true,
		pages: {},
		exportUrl: 'https://example.test/wp-admin/admin-post.php',
		exportNonce: 'n',
		preload,
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	return render(
		<RegistryProvider value={ registry }>
			<SitesView />
		</RegistryProvider>
	);
}

beforeEach( () => {
	apiFetch.mockClear();
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-sites'
	);
} );

test( 'renders the preloaded list without any request (spec 1.4, criterion 2)', async () => {
	renderView();

	expect( screen.getByText( 'Blog RH' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Site vide' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Error · 1 alert' ) ).toBeInTheDocument();
	await settle();
	expect( apiFetch ).not.toHaveBeenCalled();
} );

test( 'opening a site writes it to the address and shows its panel', async () => {
	renderView();

	fireEvent.click( screen.getByText( 'Blog RH' ) );

	expect( new URLSearchParams( window.location.search ).get( 'site' ) ).toBe(
		'1'
	);
	expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
	// Let the panel's own request start here, so that it cannot leak into the next test.
	await settle();
} );

test( 'only managers can start an analysis', () => {
	const ids = ( canManage ) =>
		getSitesActions( {
			canManage,
			onOpen() {},
			onRescan() {},
			onExport() {},
		} ).map( ( action ) => action.id );

	expect( ids( true ) ).toEqual( [
		'open',
		'admin',
		'visit',
		'export',
		'rescan',
	] );
	expect( ids( false ) ).toEqual( [ 'open', 'admin', 'visit', 'export' ] );
} );

test( '"Analyse again" is disabled while an analysis runs', () => {
	const rescan = ( isScanning ) =>
		getSitesActions( {
			canManage: true,
			isScanning,
			onOpen() {},
			onRescan() {},
			onExport() {},
		} ).find( ( action ) => action.id === 'rescan' );

	expect( rescan( false ).disabled ).toBe( false );
	expect( rescan( true ).disabled ).toBe( true );
} );

test( 'the row action "Analyse again" is disabled once an analysis has started', async () => {
	renderView();
	const openActions = () =>
		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'Actions' } )[ 0 ]
		);

	openActions();
	const rescan = await screen.findByRole( 'menuitem', {
		name: 'Analyse again',
	} );
	expect( rescan ).not.toHaveAttribute( 'aria-disabled', 'true' );
	fireEvent.click( rescan );
	await settle();
	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/scan',
		method: 'POST',
		data: { scope: 'ids', ids: [ 1 ] },
	} );

	openActions();
	expect(
		await screen.findByRole( 'menuitem', { name: 'Analyse again' } )
	).toHaveAttribute( 'aria-disabled', 'true' );
} );

test( 'a search with apostrophe, plus, percent, accents and a non-breaking space hits the preloaded path', async () => {
	const search = "O'Brien + 100% été\u00A0";
	window.history.replaceState(
		null,
		'',
		`/wp-admin/network/admin.php?page=multisite-radar-sites&s=${ encodeURIComponent( ` ${ search } ` ) }`
	);
	const path = buildPath( '/sites', {
		page: 1,
		per_page: 20,
		orderby: 'name',
		order: 'asc',
		search,
	} );

	renderView( {
		[ path ]: {
			body: [ site( 9, "Chez O'Brien" ) ],
			headers: { 'X-WP-Total': '1', 'X-WP-TotalPages': '1' },
		},
	} );

	expect( screen.getByText( "Chez O'Brien" ) ).toBeInTheDocument();
	await settle();
	expect( apiFetch ).not.toHaveBeenCalled();
} );

test( 'a list that is not preloaded shows a skeleton, not a spinner', () => {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-sites&s=nothing-preloaded'
	);
	const { container } = renderView();

	expect( screen.getByText( 'Loading sites…' ) ).toBeInTheDocument();
	expect( container.querySelector( '.components-spinner' ) ).toBeNull();
} );
