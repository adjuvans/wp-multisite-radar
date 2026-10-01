import { beforeEach, describe, expect, test, vi } from 'vitest';
import { createRegistry } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore, STORE_NAME } from '..';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

function setup( preload = {} ) {
	const registry = createRegistry();
	registry.register( createCoreStore( preload ) );
	return registry;
}

function ok( body, headers = {} ) {
	return {
		json: async () => body,
		headers: new Map( Object.entries( headers ) ),
	};
}

beforeEach( () => {
	apiFetch.mockReset();
} );

describe( 'msradar/core', () => {
	test( 'serves preloaded responses synchronously, whatever the encoding of the same path', () => {
		const registry = setup( {
			'/multisite-radar/v1/sites?search=O%27Brien&page=1': {
				body: [ { id: 1 } ],
				headers: { 'X-WP-Total': 1, 'X-WP-TotalPages': 1 },
			},
		} );

		expect(
			registry
				.select( STORE_NAME )
				.getResponse(
					"/multisite-radar/v1/sites?page=1&search=O'Brien"
				)
		).toEqual( { data: [ { id: 1 } ], total: 1, totalPages: 1 } );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	test( 'fetches a missing response once and keeps its pagination headers', async () => {
		apiFetch.mockResolvedValue(
			ok( [ { id: 2 } ], { 'X-WP-Total': '5', 'X-WP-TotalPages': '3' } )
		);
		const registry = setup();
		const path = '/multisite-radar/v1/sites?page=2';

		expect( registry.select( STORE_NAME ).getResponse( path ) ).toBeNull();
		await registry.resolveSelect( STORE_NAME ).getResponse( path );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( { path, parse: false } );
		expect( registry.select( STORE_NAME ).getResponse( path ) ).toEqual( {
			data: [ { id: 2 } ],
			total: 5,
			totalPages: 3,
		} );
	} );

	test( 'stores REST errors and retries on demand', async () => {
		apiFetch.mockRejectedValueOnce( {
			json: async () => ( {
				code: 'msradar_storage_error',
				message: 'Boom',
				data: { status: 500 },
			} ),
		} );
		const registry = setup();
		const path = '/multisite-radar/v1/sites?page=1';

		await registry.resolveSelect( STORE_NAME ).getResponse( path );
		expect( registry.select( STORE_NAME ).getError( path ) ).toEqual( {
			code: 'msradar_storage_error',
			message: 'Boom',
			status: 500,
		} );

		apiFetch.mockResolvedValueOnce( ok( [] ) );
		registry.dispatch( STORE_NAME ).retry( path );
		await registry.resolveSelect( STORE_NAME ).getResponse( path );
		expect( registry.select( STORE_NAME ).getError( path ) ).toBeNull();
		expect(
			registry.select( STORE_NAME ).getResponse( path ).data
		).toEqual( [] );
	} );

	test( 'network failures become readable errors', async () => {
		apiFetch.mockRejectedValueOnce( new TypeError( 'Failed to fetch' ) );
		const registry = setup();

		await registry
			.resolveSelect( STORE_NAME )
			.getResponse( '/multisite-radar/v1/settings' );

		expect(
			registry
				.select( STORE_NAME )
				.getError( '/multisite-radar/v1/settings' ).message
		).toBe( 'Failed to fetch' );
	} );

	test( 'invalidate forgets a family of paths and refetches them on next read', async () => {
		apiFetch.mockResolvedValue( ok( [ { id: 9 } ] ) );
		const registry = setup( {
			'/multisite-radar/v1/sites?page=1': {
				body: [ { id: 1 } ],
				headers: {},
			},
			'/multisite-radar/v1/sites/1': { body: { id: 1 }, headers: {} },
			'/multisite-radar/v1/preferences': {
				body: { sites: {} },
				headers: {},
			},
		} );

		registry
			.dispatch( STORE_NAME )
			.invalidate( '/multisite-radar/v1/sites' );

		expect(
			registry
				.select( STORE_NAME )
				.getResponse( '/multisite-radar/v1/preferences' )
		).not.toBeNull();
		await registry
			.resolveSelect( STORE_NAME )
			.getResponse( '/multisite-radar/v1/sites?page=1' );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect(
			registry
				.select( STORE_NAME )
				.getResponse( '/multisite-radar/v1/sites?page=1' ).data
		).toEqual( [ { id: 9 } ] );
	} );
} );
