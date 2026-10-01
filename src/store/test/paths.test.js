import { describe, expect, test } from 'vitest';
import { buildPath, normalizePath, NAMESPACE } from '../paths';

describe( 'buildPath', () => {
	test( 'sorts keys, drops empty values and encodes the rest', () => {
		expect( buildPath( '/preferences' ) ).toBe(
			`${ NAMESPACE }/preferences`
		);
		expect(
			buildPath( '/sites', {
				search: "O'Brien + 100% été",
				page: 1,
				rule: '',
				status: null,
				theme: undefined,
			} )
		).toBe(
			"/multisite-radar/v1/sites?page=1&search=O'Brien%20%2B%20100%25%20%C3%A9t%C3%A9"
		);
	} );
} );

describe( 'normalizePath', () => {
	test( 'gives the same key for the PHP-encoded preload path and the client path', () => {
		const php =
			'/multisite-radar/v1/sites?search=O%27Brien%20%2B%20100%25%20%C3%A9t%C3%A9&page=1';
		expect( normalizePath( php ) ).toBe(
			buildPath( '/sites', { search: "O'Brien + 100% été", page: 1 } )
		);
	} );

	test( 'is idempotent on built paths and keeps paths without a query', () => {
		const built = buildPath( '/sites', {
			alert_level: 'warning,error',
			page: 2,
		} );
		expect( normalizePath( built ) ).toBe( built );
		expect( normalizePath( `${ NAMESPACE }/settings` ) ).toBe(
			`${ NAMESPACE }/settings`
		);
	} );

	test( 'reads plus signs as spaces and survives malformed escapes', () => {
		expect( normalizePath( '/x?b=a+b&a=%E0%A4%A' ) ).toBe(
			'/x?a=%25E0%25A4%25A&b=a%20b'
		);
	} );
} );
