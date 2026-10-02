import { beforeEach, expect, test, vi } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import UsersView from '..';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => new Promise( () => {} ) ),
} ) );

beforeEach( () => {
	apiFetch.mockClear();
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-users'
	);
} );

test( 'renders the preloaded accounts without any request', async () => {
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/users?order=asc&orderby=login&page=1&per_page=20':
			{
				body: [
					{
						id: 1,
						login: 'admin',
						display_name: 'Admin',
						super_admin: true,
						sites_count: 4,
						registered_gmt: '2026-01-01T10:00:00',
						edit_url:
							'https://example.test/wp-admin/network/user-edit.php?user_id=1',
					},
					{
						id: 7,
						login: 'orphan',
						display_name: 'Orphan',
						super_admin: false,
						sites_count: 0,
						registered_gmt: null,
						edit_url:
							'https://example.test/wp-admin/network/user-edit.php?user_id=7',
					},
				],
				headers: { 'X-WP-Total': '2', 'X-WP-TotalPages': '1' },
			},
	};
	window.msradarAdmin = {
		view: 'users',
		canManage: true,
		pages: {},
		preload,
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );

	render(
		<RegistryProvider value={ registry }>
			<UsersView />
		</RegistryProvider>
	);

	expect( screen.getByText( 'admin' ) ).toBeInTheDocument();
	// « Super admin » est aussi l'en-tête de colonne : on cible le badge.
	expect(
		screen
			.getAllByText( 'Super admin' )
			.some( ( node ) => node.classList.contains( 'msradar-badge' ) )
	).toBe( true );
	expect( screen.getByText( 'No site' ) ).toHaveClass(
		'msradar-badge--warning'
	);
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
	expect( apiFetch ).not.toHaveBeenCalled();
} );
