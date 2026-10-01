import { expect, test, vi } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../../store';
import AlertsView from '..';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => new Promise( () => {} ) ),
} ) );

test( 'renders the preloaded alerts without any request', async () => {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-alerts'
	);
	const preload = {
		'/multisite-radar/v1/preferences': { body: {}, headers: {} },
		'/multisite-radar/v1/alerts/summary': {
			body: {
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
		'/multisite-radar/v1/alerts?order=asc&orderby=rule&page=1&per_page=20':
			{
				body: [
					{
						id: '2:no_users',
						site: {
							id: 2,
							name: 'Site vide',
							url: 'https://example.test/vide/',
							admin_url: 'https://example.test/vide/wp-admin/',
						},
						rule: 'no_users',
						label: 'Site without users',
						severity: 'error',
						message: 'No user is attached to this site.',
					},
				],
				headers: { 'X-WP-Total': '1', 'X-WP-TotalPages': '1' },
			},
	};
	window.msradarAdmin = {
		view: 'alerts',
		canManage: true,
		pages: {},
		preload,
	};
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );

	render(
		<RegistryProvider value={ registry }>
			<AlertsView />
		</RegistryProvider>
	);

	expect( screen.getByText( 'Site vide' ) ).toBeInTheDocument();
	// Sorted by rule: DataViews shows the group header.
	expect( screen.getByText( 'Rule: no_users' ) ).toBeInTheDocument();
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
	expect(
		screen.getByText( 'No user is attached to this site.' )
	).toBeInTheDocument();
	expect( apiFetch ).not.toHaveBeenCalled();
} );
