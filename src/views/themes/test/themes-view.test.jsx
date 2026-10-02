import { beforeEach, expect, test, vi } from 'vitest';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import ThemesView from '..';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => new Promise( () => {} ) ),
} ) );

const THEME = {
	id: 'parent-theme',
	stylesheet: 'parent-theme',
	name: 'Parent Theme',
	version: '1.0',
	installed: true,
	parent: null,
	allowed_on_network: true,
	active_count: 2,
	parent_count: 1,
	sites_count: 3,
	status: 'used',
	update_version: null,
};

function renderView() {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/inventory/summary': {
			body: {
				pending_sites: 0,
				networks: 1,
				plugins: {
					installed: 0,
					network: 0,
					unused: 0,
					missing: 0,
					updates: 0,
				},
				themes: { installed: 2, unused: 1, missing: 0, updates: 0 },
			},
			headers: {},
		},
		'/multisite-radar/v1/themes?order=asc&orderby=name&page=1&per_page=20':
			{
				body: [
					THEME,
					{
						...THEME,
						id: 'spare',
						stylesheet: 'spare',
						name: 'Spare',
						active_count: 0,
						parent_count: 0,
						sites_count: 0,
						status: 'unused',
					},
				],
				headers: { 'X-WP-Total': '2', 'X-WP-TotalPages': '1' },
			},
		'/multisite-radar/v1/themes/parent-theme/sites?page=1&per_page=20': {
			body: [
				{
					id: 2,
					name: 'Blog RH',
					url: 'https://example.test/rh/',
					theme: {
						stylesheet: 'child-theme',
						template: 'parent-theme',
					},
				},
				{
					id: 3,
					name: 'Atelier',
					url: 'https://example.test/atelier/',
					theme: {
						stylesheet: 'parent-theme',
						template: 'parent-theme',
					},
				},
			],
			headers: { 'X-WP-Total': '2', 'X-WP-TotalPages': '1' },
		},
	};
	window.msradarAdmin = {
		view: 'themes',
		canManage: true,
		pages: {
			sites: 'https://example.test/wp-admin/network/admin.php?page=multisite-radar-sites',
		},
		preload,
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
	return render(
		<RegistryProvider value={ registry }>
			<ThemesView />
		</RegistryProvider>
	);
}

beforeEach( () => {
	apiFetch.mockClear();
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-themes'
	);
} );

test( 'renders the preloaded themes with their use as a parent', async () => {
	renderView();

	expect( screen.getByText( 'Parent Theme' ) ).toBeInTheDocument();
	expect( screen.getByText( '3 (1 as parent)' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Unused' ) ).toBeInTheDocument();
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
	expect( apiFetch ).not.toHaveBeenCalled();
} );

test( 'the panel tells which sites use the theme as a parent', () => {
	renderView();

	fireEvent.click( screen.getByText( 'Parent Theme' ) );

	const dialog = screen.getByRole( 'dialog', { name: 'Parent Theme' } );
	expect(
		within( dialog ).getByText( 'Parent of the active theme child-theme' )
	).toBeInTheDocument();
	expect(
		within( dialog ).getAllByText( /Parent of the active theme/ )
	).toHaveLength( 1 );
} );
