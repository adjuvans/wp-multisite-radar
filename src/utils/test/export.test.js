import { beforeEach, expect, test } from 'vitest';
import { columnsFor, exportLink } from '../export';

beforeEach( () => {
	window.msradarAdmin = {
		exportUrl: 'https://example.test/wp-admin/admin-post.php',
		exportNonce: 'nonce123',
	};
} );

test( 'export links carry the action, the nonce, the resource and the arguments', () => {
	const url = new URL(
		exportLink( 'plugins', 'json', { status: 'unused', has_update: 1 } )
	);

	expect( Object.fromEntries( url.searchParams ) ).toEqual( {
		action: 'msradar_export',
		_wpnonce: 'nonce123',
		resource: 'plugins',
		format: 'json',
		status: 'unused',
		has_update: '1',
	} );
} );

test( 'export columns start with the fixed ones, then follow the visible fields without duplicates', () => {
	expect(
		columnsFor(
			[ 'name', 'file' ],
			{ status: [ 'status', 'network_active' ], version: [ 'version' ] },
			[ 'status', 'unknown', 'version', 'status' ]
		)
	).toEqual( [ 'name', 'file', 'status', 'network_active', 'version' ] );
} );
