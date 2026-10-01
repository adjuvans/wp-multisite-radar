import { expect, test } from 'vitest';
import { filterValue, toFilters } from '../filters';

test( 'toFilters drops empty values and filterValue reads them back', () => {
	const filters = toFilters( [
		{ field: 'alert_level', operator: 'isAny', value: [ 'error' ] },
		{ field: 'status', operator: 'isAny', value: [] },
		{ field: 'rule', operator: 'is', value: '' },
		{ field: 'registry_status', operator: 'isAny', value: null },
	] );

	expect( filters ).toEqual( [
		{ field: 'alert_level', operator: 'isAny', value: [ 'error' ] },
	] );
	expect( filterValue( filters, 'alert_level', [] ) ).toEqual( [ 'error' ] );
	expect( filterValue( filters, 'rule', '' ) ).toBe( '' );
	expect( filterValue( undefined, 'rule', 'x' ) ).toBe( 'x' );
} );
