import { beforeEach, expect, test, vi } from 'vitest';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import PluginsView from '..';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => new Promise( () => {} ) ),
} ) );

function plugin( id, name, overrides = {} ) {
	return {
		id,
		file: `${ id }.php`,
		name,
		version: '1.0',
		installed: true,
		network_active: false,
		sites_count: 1,
		status: 'local',
		update_version: null,
		...overrides,
	};
}

async function settle() {
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
}

function renderView( pending = 0 ) {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/inventory/summary': {
			body: {
				pending_sites: pending,
				plugins: {
					installed: 2,
					network: 1,
					unused: 0,
					missing: 0,
					updates: 1,
				},
				themes: { installed: 1, unused: 0, missing: 0, updates: 0 },
			},
			headers: {},
		},
		'/multisite-radar/v1/plugins?order=asc&orderby=name&page=1&per_page=20':
			{
				body: [
					plugin( 'gamma/gamma', 'Gamma', {
						network_active: true,
						status: 'network',
						sites_count: 12,
					} ),
					plugin( 'my plugin/my.plugin', 'My plugin', {
						update_version: '2.0',
					} ),
				],
				headers: { 'X-WP-Total': '2', 'X-WP-TotalPages': '1' },
			},
	};
	window.msradarAdmin = {
		view: 'plugins',
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
			<PluginsView />
		</RegistryProvider>
	);
}

beforeEach( () => {
	apiFetch.mockClear();
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-plugins'
	);
} );

test( 'renders the preloaded plugins without any request (spec 1.4, criterion 2)', async () => {
	renderView();

	expect( screen.getByText( 'My plugin' ) ).toBeInTheDocument();
	expect( screen.getByText( 'my plugin/my.plugin.php' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Network activated' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Active on some sites' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Version 2.0 available' ) ).toBeInTheDocument();
	expect(
		screen.queryByText( /have not been analysed yet/ )
	).not.toBeInTheDocument();
	await settle();
	expect( apiFetch ).not.toHaveBeenCalled();
} );

test( 'warns while some sites have not been analysed', () => {
	const { container } = renderView( 3 );

	// Le Notice est aussi annoncé dans la région a11y-speak, hors du conteneur de la vue.
	expect(
		within( container ).getByText(
			'3 sites have not been analysed yet: what they use is not counted below.'
		)
	).toBeInTheDocument();
} );

test( 'a plugin opens the panel of its sites with an encoded path', async () => {
	renderView();

	fireEvent.click( screen.getByText( 'My plugin' ) );

	expect(
		screen.getByRole( 'dialog', { name: 'My plugin' } )
	).toBeInTheDocument();
	await settle();
	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/plugins/my%20plugin/my.plugin/sites?page=1&per_page=20',
		parse: false,
	} );
} );

test( 'a network activated plugin says so in its panel', async () => {
	renderView();

	fireEvent.click( screen.getByText( 'Gamma' ) );

	expect(
		within( screen.getByRole( 'dialog', { name: 'Gamma' } ) ).getByText(
			'Network activated: every site of the network loads this plugin.'
		)
	).toBeInTheDocument();
	await settle();
} );
