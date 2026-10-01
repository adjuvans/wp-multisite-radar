import { expect, test } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useUrlState } from '../use-url-state';

const parse = ( query ) => ( {
	search: query.s || '',
	page: Number( query.paged || 1 ),
} );
const serialize = ( state ) => ( {
	...( state.search ? { s: state.search } : {} ),
	...( state.page > 1 ? { paged: String( state.page ) } : {} ),
} );

test( 'reads the address and writes changes back, keeping the WordPress page parameter', () => {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/network/admin.php?page=multisite-radar-sites&s=blog%20rh&paged=2'
	);

	const { result } = renderHook( () => useUrlState( parse, serialize ) );
	expect( result.current[ 0 ] ).toEqual( { search: 'blog rh', page: 2 } );

	act( () => result.current[ 1 ]( { search: '', page: 1 } ) );
	expect( window.location.search ).toBe( '?page=multisite-radar-sites' );

	act( () => result.current[ 1 ]( { search: "O'Brien", page: 3 } ) );
	const query = new URLSearchParams( window.location.search );
	expect( query.get( 'page' ) ).toBe( 'multisite-radar-sites' );
	expect( query.get( 's' ) ).toBe( "O'Brien" );
	expect( query.get( 'paged' ) ).toBe( '3' );
} );
