import { expect, test, vi } from 'vitest';
import { renderHook } from '@testing-library/react';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { createCoreStore } from '../../store';
import { useResource } from '../use-resource';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

test( 'keeps the previous data while the next page loads', () => {
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
	const registry = createRegistry();
	registry.register(
		createCoreStore( {
			'/multisite-radar/v1/sites?page=1': {
				body: [ { id: 1 } ],
				headers: { 'X-WP-Total': '1' },
			},
		} )
	);
	const wrapper = ( { children } ) => (
		<RegistryProvider value={ registry }>{ children }</RegistryProvider>
	);

	const { result, rerender } = renderHook(
		( { path } ) => useResource( path ),
		{
			initialProps: { path: '/multisite-radar/v1/sites?page=1' },
			wrapper,
		}
	);
	expect( result.current ).toMatchObject( {
		data: [ { id: 1 } ],
		total: 1,
		isLoading: false,
		isFresh: true,
	} );

	rerender( { path: '/multisite-radar/v1/sites?page=2' } );
	expect( result.current ).toMatchObject( {
		data: [ { id: 1 } ],
		isLoading: true,
		isFresh: false,
	} );

	rerender( { path: null } );
	expect( result.current.isLoading ).toBe( false );
} );
