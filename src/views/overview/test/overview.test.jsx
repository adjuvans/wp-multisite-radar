import { beforeEach, expect, test, vi } from 'vitest';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import OverviewView from '..';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => new Promise( () => {} ) ),
} ) );
vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

const SITES_URL =
	'https://example.test/wp-admin/network/admin.php?page=multisite-radar-sites';

function summary( overrides = {} ) {
	return {
		total_sites: 12,
		scanned_sites: 10,
		pending_sites: 2,
		sites_with_alerts: 4,
		by_severity: { error: 1, warning: 2, info: 1 },
		by_rule: [
			{
				rule: 'high_media',
				label: 'Many media files',
				severity: 'info',
				enabled: true,
				count: 1,
			},
			{
				rule: 'inactive',
				label: 'Inactive site',
				severity: 'warning',
				enabled: true,
				count: 3,
			},
			{
				rule: 'no_users',
				label: 'Site without users',
				severity: 'error',
				enabled: true,
				count: 1,
			},
			{
				rule: 'disabled',
				label: 'Disabled rule',
				severity: 'error',
				enabled: false,
				count: 5,
			},
		],
		...overrides,
	};
}

function renderView( { canManage = true, data = summary() } = {} ) {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/alerts/summary': { body: data, headers: {} },
		'/multisite-radar/v1/scan/status': {
			body: {
				total: 12,
				remaining: 2,
				pending: 2,
				locked: false,
				last_full_scan_gmt: null,
				next_run_gmt: null,
			},
			headers: {},
		},
	};
	window.msradarAdmin = {
		view: 'overview',
		canManage,
		pages: { sites: SITES_URL },
		preload,
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	return render(
		<RegistryProvider value={ registry }>
			<OverviewView />
		</RegistryProvider>
	);
}

beforeEach( () => {
	apiFetch.mockClear();
} );

test( 'tiles link to the sites filtered by severity', async () => {
	renderView();

	const errors = screen.getByRole( 'link', { name: /Sites with errors/ } );
	expect( errors ).toHaveAttribute(
		'href',
		`${ SITES_URL }&alert_level=error`
	);
	expect(
		screen.getByRole( 'link', { name: /12\s*Sites/ } )
	).toHaveAttribute( 'href', SITES_URL );
	expect( screen.getByText( '2 awaiting analysis' ) ).toBeInTheDocument();
	await act( () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) ) );
	expect( apiFetch ).not.toHaveBeenCalled();
} );

test( 'to review lists enabled rules with sites, the most severe first', () => {
	renderView();

	const links = screen.getAllByRole( 'link', {
		name: /Site without users|Inactive site|Many media files|Disabled rule/,
	} );
	expect( links.map( ( link ) => link.textContent ) ).toEqual( [
		'Site without users',
		'Inactive site',
		'Many media files',
	] );
	expect( links[ 0 ] ).toHaveAttribute(
		'href',
		`${ SITES_URL }&rule=no_users`
	);
	expect( screen.getByText( '3 sites' ) ).toBeInTheDocument();
} );

test( 'the first launch explains the initial analysis', () => {
	const { container } = renderView( {
		data: summary( {
			scanned_sites: 0,
			pending_sites: 12,
			by_severity: { error: 0, warning: 0, info: 0 },
			by_rule: [],
		} ),
	} );

	expect(
		within( container ).getByText( /has not analysed your 12 sites yet/ )
	).toBeInTheDocument();
	expect(
		screen.getByRole( 'button', { name: 'Start the analysis' } )
	).toBeInTheDocument();
} );

test( 'starting an analysis is reserved to managers', () => {
	renderView( { canManage: false } );
	expect(
		screen.queryByRole( 'button', { name: 'Analyse all sites' } )
	).toBeNull();
} );

test( 'Analyse all sites starts a full analysis', () => {
	renderView();

	fireEvent.click(
		screen.getByRole( 'button', { name: 'Analyse all sites' } )
	);

	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/scan',
		method: 'POST',
		data: { scope: 'all' },
	} );
} );
