import { beforeEach, expect, test, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';
import { createCoreStore, STORE_NAME } from '../../store';
import { useScan } from '../use-scan';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

function setup( preload = {} ) {
	const registry = createRegistry();
	registry.register( noticesStore );
	registry.register( createCoreStore( preload ) );
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
	apiFetch.mockImplementation( ( { path, data } ) => {
		const handler = handlers[ path.replace( '/multisite-radar/v1', '' ) ];
		return handler( data );
	} );
}

function batchCalls() {
	return apiFetch.mock.calls
		.map( ( [ options ] ) => options )
		.filter( ( options ) => options.path.endsWith( '/scan/batch' ) );
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

test( 'a targeted analysis sends its ids with every batch and stops once those sites are done', async () => {
	const targeted = [
		{ processed: 1, remaining: 1, locked: false },
		{ processed: 1, remaining: 0, locked: false },
	];
	route( {
		// Le réseau a un arriéré de 40 sites : il ne doit ni compter ni prolonger l'analyse ciblée.
		'/scan': async () => ( { remaining: 40, total: 50 } ),
		'/scan/batch': async ( data ) =>
			data?.ids
				? targeted.shift()
				: { processed: 0, remaining: 40, locked: true },
	} );
	const { registry, hook } = setup();

	await act( async () => {
		await hook.result.current.start( { scope: 'ids', ids: [ 3, 7 ] } );
	} );

	expect( batchCalls() ).toEqual( [
		{
			path: '/multisite-radar/v1/scan/batch',
			method: 'POST',
			data: { ids: [ 3, 7 ] },
		},
		{
			path: '/multisite-radar/v1/scan/batch',
			method: 'POST',
			data: { ids: [ 3, 7 ] },
		},
	] );
	expect( hook.result.current ).toMatchObject( {
		running: false,
		processed: 2,
		total: 2,
		remaining: 0,
		deferred: false,
	} );
	expect( messages( registry ) ).toContain( 'Analysis complete.' );
} );

test( 'an untargeted analysis sends no ids', async () => {
	route( {
		'/scan': async () => ( { remaining: 1, total: 1 } ),
		'/scan/batch': async () => ( {
			processed: 1,
			remaining: 0,
			locked: false,
		} ),
	} );
	const { hook } = setup();

	await act( async () => {
		await hook.result.current.start( { scope: 'dirty' } );
	} );

	expect( batchCalls() ).toEqual( [
		{ path: '/multisite-radar/v1/scan/batch', method: 'POST' },
	] );
} );

test( 'a second start while an analysis runs returns the running one instead of starting another', async () => {
	let release;
	const marks = [];
	route( {
		'/scan': ( data ) => {
			marks.push( data );
			return new Promise( ( resolve ) => {
				release = () => resolve( { remaining: 5, total: 5 } );
			} );
		},
		'/scan/batch': async () => ( {
			processed: 1,
			remaining: 0,
			locked: false,
		} ),
	} );
	const { hook } = setup();

	let first;
	let second;
	await act( async () => {
		first = hook.result.current.start( { scope: 'ids', ids: [ 1 ] } );
		second = hook.result.current.start( { scope: 'ids', ids: [ 2 ] } );
	} );
	expect( second ).toBe( first );
	expect( marks ).toEqual( [ { scope: 'ids', ids: [ 1 ] } ] );

	await act( async () => {
		release();
		await first;
	} );
	expect( hook.result.current ).toMatchObject( {
		running: false,
		processed: 1,
		total: 1,
	} );

	// Une fois l'analyse terminée, une nouvelle peut partir.
	await act( async () => {
		const next = hook.result.current.start( { scope: 'all' } );
		release();
		await next;
	} );
	expect( marks ).toEqual( [
		{ scope: 'ids', ids: [ 1 ] },
		{ scope: 'all' },
	] );
} );

test( 'a failed batch still refreshes the data of the sites already analysed', async () => {
	const path = '/multisite-radar/v1/sites?page=1';
	const batches = [
		async () => ( { processed: 1, remaining: 1, locked: false } ),
		async () => {
			throw {
				code: 'msradar_storage_error',
				message: 'Multisite Radar could not read its data.',
			};
		},
	];
	route( {
		'/scan': async () => ( { remaining: 2, total: 2 } ),
		'/scan/batch': () => batches.shift()(),
	} );
	const { registry, hook } = setup( {
		[ path ]: { body: [ { id: 1 } ], headers: {} },
	} );

	await act( async () => {
		await hook.result.current.start( { scope: 'all' } );
	} );

	expect( messages( registry ) ).toContain(
		'Multisite Radar could not read its data.'
	);
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
	expect( registry.select( STORE_NAME ).getResponse( path ) ).toBeNull();
} );
