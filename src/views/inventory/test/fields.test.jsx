import { expect, test } from 'vitest';
import { render } from '@testing-library/react';
import { getPluginsFields } from '../../plugins/fields';
import { getThemesFields } from '../../themes/fields';

function badge( fields, id, item ) {
	const field = fields.find( ( candidate ) => candidate.id === id );
	const { container } = render( field.render( { item, field } ) );
	return container.querySelector( '.msradar-badge' );
}

test( 'states have their colour: green in use, orange unused, red not installed', () => {
	const plugins = getPluginsFields();
	expect( badge( plugins, 'status', { status: 'network' } ) ).toHaveClass(
		'msradar-badge--success'
	);
	expect( badge( plugins, 'status', { status: 'local' } ) ).toHaveClass(
		'msradar-badge--success'
	);
	expect( badge( plugins, 'status', { status: 'unused' } ) ).toHaveClass(
		'msradar-badge--warning'
	);
	expect( badge( plugins, 'status', { status: 'missing' } ) ).toHaveClass(
		'msradar-badge--error'
	);

	const themes = getThemesFields();
	expect( badge( themes, 'status', { status: 'used' } ) ).toHaveClass(
		'msradar-badge--success'
	);
	expect( badge( themes, 'status', { status: 'unused' } ) ).toHaveClass(
		'msradar-badge--warning'
	);
	expect( badge( themes, 'status', { status: 'missing' } ) ).toHaveClass(
		'msradar-badge--error'
	);
} );

test( 'an available update is blue', () => {
	expect(
		badge( getPluginsFields(), 'update_version', {
			update_version: '2.0.0',
		} )
	).toHaveClass( 'msradar-badge--info' );
} );

test( 'state, version and update are sortable, and the state filter stays in view', () => {
	const fields = getPluginsFields();
	for ( const id of [ 'status', 'version', 'update_version' ] ) {
		const field = fields.find( ( candidate ) => candidate.id === id );
		expect( field.enableSorting ).not.toBe( false );
	}
	expect(
		fields.find( ( field ) => field.id === 'status' ).filterBy.isPrimary
	).toBe( true );
} );
