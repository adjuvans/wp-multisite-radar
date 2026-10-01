import { beforeEach, expect, test } from 'vitest';
import { exportColumns, exportUrl } from '../export';
import { parseSitesQuery } from '../query';

beforeEach( () => {
	window.msradarAdmin = {
		exportUrl: 'https://example.test/wp-admin/admin-post.php',
		exportNonce: 'nonce123',
	};
} );

test( 'export columns follow the visible fields', () => {
	expect( exportColumns( [ 'users_count', 'alert_level', 'rule' ] ) ).toEqual(
		[
			'id',
			'name',
			'url',
			'users_count',
			'admins_count',
			'alert_level',
			'alerts_count',
			'alert_rules',
		]
	);
} );

test( 'the export URL carries the nonce, the filters, the columns and the selection', () => {
	const state = parseSitesQuery( {
		s: "O'Brien",
		alert_level: 'error',
		orderby: 'users_count',
		order: 'desc',
		paged: '3',
	} );

	const url = new URL( exportUrl( 'csv', state, [ 'theme' ], [ 3, 1 ] ) );

	expect( url.origin + url.pathname ).toBe(
		'https://example.test/wp-admin/admin-post.php'
	);
	expect( Object.fromEntries( url.searchParams ) ).toEqual( {
		action: 'msradar_export',
		_wpnonce: 'nonce123',
		resource: 'sites',
		format: 'csv',
		fields: 'id,name,url,theme',
		orderby: 'users_count',
		order: 'desc',
		search: "O'Brien",
		alert_level: 'error',
		include: '3,1',
	} );
} );
