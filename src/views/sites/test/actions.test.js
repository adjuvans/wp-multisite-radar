import { expect, test, vi } from 'vitest';
import { getSitesActions } from '../actions';

test( 'no site action is primary: the detail opens from the row and the actions column keeps only its menu', () => {
	const actions = getSitesActions( {
		canManage: true,
		onOpen: vi.fn(),
		onRescan: vi.fn(),
		onExport: vi.fn(),
	} );
	expect( actions.filter( ( action ) => action.isPrimary ) ).toEqual( [] );
	expect( actions.map( ( action ) => action.id ) ).toContain( 'open' );
} );
