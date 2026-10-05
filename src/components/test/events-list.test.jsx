import { beforeEach, expect, test, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../store';
import EventsList from '../events-list';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => new Promise( () => {} ) ),
} ) );

const EVENTS = [
	{
		id: 2,
		type: 'plugin_activated',
		site: null,
		subject: 'akismet/akismet.php',
		label: 'Akismet',
		message: 'Plugin Akismet network activated.',
		created_gmt: '2026-09-10T10:00:00',
	},
	{
		id: 1,
		type: 'alert_raised',
		site: {
			id: 5,
			name: 'Blog RH',
			url: 'http://example.test/rh/',
			admin_url: 'http://example.test/rh/wp-admin/',
		},
		subject: 'no_users',
		label: 'Site without users',
		message: 'New alert: Site without users.',
		created_gmt: '2026-09-09T10:00:00',
	},
];

function setup(
	body = EVENTS,
	props = {},
	path = '/multisite-radar/v1/events?page=1&per_page=20'
) {
	window.msradarAdmin = {
		pages: {
			sites: 'https://example.test/wp-admin/network/admin.php?page=multisite-radar-sites',
		},
	};
	const registry = createRegistry();
	registry.register(
		createCoreStore( {
			[ path ]: {
				body,
				headers: {
					'X-WP-Total': String( body.length ),
					'X-WP-TotalPages': '1',
				},
			},
		} )
	);
	return render(
		<RegistryProvider value={ registry }>
			<EventsList { ...props } />
		</RegistryProvider>
	);
}

beforeEach( () => {
	apiFetch.mockClear();
} );

test( 'each change shows its date, its site and its message', () => {
	setup();

	expect(
		screen.getByText( 'Plugin Akismet network activated.' )
	).toBeInTheDocument();
	expect( screen.getByText( 'Whole network' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'link', { name: 'Blog RH' } ) ).toHaveAttribute(
		'href',
		expect.stringContaining( 'site=5' )
	);
} );

test( 'filtering by kind of change asks for that kind only', async () => {
	setup();

	fireEvent.change(
		screen.getByRole( 'combobox', { name: 'Kind of change' } ),
		{
			target: { value: 'alert_raised' },
		}
	);

	await waitFor( () => {
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: expect.stringContaining( 'type=alert_raised' ),
			} )
		);
	} );
} );

test( 'without changes, the list says so', () => {
	setup( [] );

	expect( screen.getByText( 'No change recorded yet.' ) ).toBeInTheDocument();
} );

test( 'the compact list of one site has neither filter nor site column', () => {
	setup(
		EVENTS.slice( 1 ),
		{ site: 5, perPage: 5, compact: true },
		'/multisite-radar/v1/events?page=1&per_page=5&site=5'
	);

	expect( screen.queryByRole( 'combobox' ) ).toBeNull();
	expect( screen.queryByRole( 'columnheader', { name: 'Site' } ) ).toBeNull();
	expect(
		screen.getByText( 'New alert: Site without users.' )
	).toBeInTheDocument();
} );

test( 'several lists keep their own, reachable, type filters', () => {
	const registry = createRegistry();
	registry.register( createCoreStore( {} ) );
	render(
		<RegistryProvider value={ registry }>
			<EventsList />
			<EventsList perPage={ 5 } />
		</RegistryProvider>
	);

	const filters = screen.getAllByRole( 'combobox', {
		name: 'Kind of change',
	} );

	expect( filters ).toHaveLength( 2 );
	expect( filters[ 0 ].id ).not.toBe( filters[ 1 ].id );
} );

test( 'with a filter and no change, the list says that none of this kind was recorded', async () => {
	const registry = createRegistry();
	registry.register(
		createCoreStore( {
			'/multisite-radar/v1/events?page=1&per_page=20': {
				body: EVENTS,
				headers: { 'X-WP-Total': '2', 'X-WP-TotalPages': '1' },
			},
			'/multisite-radar/v1/events?page=1&per_page=20&type=site_deleted': {
				body: [],
				headers: { 'X-WP-Total': '0', 'X-WP-TotalPages': '0' },
			},
		} )
	);
	render(
		<RegistryProvider value={ registry }>
			<EventsList />
		</RegistryProvider>
	);

	fireEvent.change(
		screen.getByRole( 'combobox', { name: 'Kind of change' } ),
		{ target: { value: 'site_deleted' } }
	);

	expect(
		await screen.findByText( 'No change of this kind recorded yet.' )
	).toBeInTheDocument();
} );

test( 'the list of another site starts again from the first page', async () => {
	const registry = createRegistry();
	registry.register(
		createCoreStore( {
			'/multisite-radar/v1/events?page=1&per_page=20&site=5': {
				body: EVENTS.slice( 1 ),
				headers: { 'X-WP-Total': '30', 'X-WP-TotalPages': '2' },
			},
		} )
	);
	const view = ( site ) => (
		<RegistryProvider value={ registry }>
			<EventsList site={ site } />
		</RegistryProvider>
	);
	const { rerender } = render( view( 5 ) );

	fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
	await waitFor( () => {
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/multisite-radar/v1/events?page=2&per_page=20&site=5',
			} )
		);
	} );

	rerender( view( 6 ) );
	await waitFor( () => {
		expect( apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				path: '/multisite-radar/v1/events?page=1&per_page=20&site=6',
			} )
		);
	} );
} );
