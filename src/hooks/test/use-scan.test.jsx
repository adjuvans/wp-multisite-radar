import { beforeEach, expect, test, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';
import { createCoreStore } from '../../store';
import { useScan } from '../use-scan';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

function setup() {
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( {} ) );
	const wrapper = ( { children } ) => (
		<RegistryProvider value={ registry }>{ children }</RegistryProvider>
	);
	const hook = renderHook( () => useScan( { waitMs: 0, maxWaits: 2 } ), {
		wrapper,
	} );
	return { registry, hook };
}

function messages( registry ) {
	return registry
		.select( noticesStore )
		.getNotices()
		.map( ( notice ) => notice.content );
}

function route( handlers ) {
	apiFetch.mockImplementation( ( { path } ) => {
		const handler = handlers[ path.replace( '/multisite-radar/v1', '' ) ];
		return handler();
	} );
}

beforeEach( () => {
	apiFetch.mockReset();
	speak.mockReset();
} );

test( 'marks the sites, then runs batches until none remains', async () => {
	const batches = [
		{ processed: 2, remaining: 1, locked: false },
		{ processed: 1, remaining: 0, locked: false },
	];
	route( {
		'/scan': async () => ( { remaining: 3, total: 3 } ),
		'/scan/batch': async () => batches.shift(),
	} );
	const { registry, hook } = setup();

	await act( async () => {
		await hook.result.current.start( { scope: 'all' } );
	} );

	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/multisite-radar/v1/scan',
		method: 'POST',
		data: { scope: 'all' },
	} );
	expect( hook.result.current ).toMatchObject( {
		running: false,
		processed: 3,
		total: 3,
		remaining: 0,
		deferred: false,
	} );
	expect( messages( registry ) ).toContain( 'Analysis complete.' );
	expect( speak ).toHaveBeenCalledWith( 'Analysis complete.' );
} );

test( 'gives up after repeated batches without progress (the cron holds the lock) and says so', async () => {
	route( {
		'/scan': async () => ( { remaining: 5, total: 5 } ),
		'/scan/batch': async () => ( {
			processed: 0,
			remaining: 5,
			locked: true,
		} ),
	} );
	const { registry, hook } = setup();

	await act( async () => {
		await hook.result.current.start( { scope: 'dirty' } );
	} );

	expect( apiFetch ).toHaveBeenCalledTimes( 4 ); // POST /scan, puis maxWaits + 1 lots.
	expect( hook.result.current ).toMatchObject( {
		running: false,
		deferred: true,
		remaining: 5,
	} );
	expect( messages( registry ) ).toContain(
		'The analysis continues in the background.'
	);
} );

test( 'reports a refused request', async () => {
	route( {
		'/scan': async () => {
			throw {
				code: 'rest_forbidden',
				message: 'Sorry, you are not allowed to do that.',
			};
		},
	} );
	const { registry, hook } = setup();

	await act( async () => {
		await hook.result.current.start( { scope: 'all' } );
	} );

	expect( hook.result.current.running ).toBe( false );
	expect( messages( registry ) ).toContain(
		'Sorry, you are not allowed to do that.'
	);
} );

test( 'waits while the lock is held, then resumes and completes', async () => {
	const batches = [
		{ processed: 0, remaining: 3, locked: true },
		{ processed: 3, remaining: 0, locked: false },
	];
	route( {
		'/scan': async () => ( { remaining: 3, total: 3 } ),
		'/scan/batch': async () => batches.shift(),
	} );
	const { registry, hook } = setup();

	await act( async () => {
		await hook.result.current.start( { scope: 'all' } );
	} );

	expect( hook.result.current ).toMatchObject( {
		running: false,
		processed: 3,
		remaining: 0,
		deferred: false,
	} );
	expect( messages( registry ) ).toContain( 'Analysis complete.' );
} );
