import { afterEach, expect, test, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { DataViews } from '..';

const DATA = [
	{ id: 1, name: 'Alpha', size: 3 },
	{ id: 2, name: 'Beta', size: 5 },
];
const FIELDS = [
	{ id: 'name', type: 'text', label: 'Name', enableHiding: false },
	{ id: 'size', type: 'integer', label: 'Size' },
];
const VIEW = {
	type: 'table',
	titleField: 'name',
	fields: [ 'size' ],
	page: 1,
	perPage: 20,
	layout: {},
};

afterEach( () => vi.restoreAllMocks() );

// Comme les écrans, une fonction getItemId nouvelle à chaque rendu.
function views( props = {} ) {
	return (
		<DataViews
			data={ DATA }
			fields={ FIELDS }
			view={ VIEW }
			onChangeView={ () => {} }
			defaultLayouts={ { table: {} } }
			paginationInfo={ { totalItems: 2, totalPages: 1 } }
			getItemId={ ( item ) => String( item.id ) }
			{ ...props }
		/>
	);
}

function renderViews( props = {} ) {
	return render( views( props ) );
}

/**
 * Sélection de texte simulée, de `node` jusqu'à la fin de son texte.
 *
 * @param {Node} node Nœud texte où commence et finit la sélection.
 */
function selectTextIn( node ) {
	vi.spyOn( window, 'getSelection' ).mockReturnValue( {
		isCollapsed: false,
		toString: () => node.textContent,
		anchorNode: node,
		focusNode: node,
	} );
}

test( 'a click anywhere in a row opens its item, once', () => {
	const onClickItem = vi.fn();
	renderViews( { onClickItem } );

	fireEvent.click( screen.getByText( '5' ) );
	expect( onClickItem ).toHaveBeenCalledTimes( 1 );
	expect( onClickItem ).toHaveBeenCalledWith( DATA[ 1 ] );

	fireEvent.click( screen.getByText( 'Alpha' ) );
	expect( onClickItem ).toHaveBeenCalledTimes( 2 );
	expect( onClickItem ).toHaveBeenLastCalledWith( DATA[ 0 ] );
} );

test( 'a Ctrl-click, a text selection or an item that is not clickable opens nothing', () => {
	const onClickItem = vi.fn();
	const { unmount } = renderViews( { onClickItem } );
	fireEvent.click( screen.getByText( '5' ), { ctrlKey: true } );
	vi.spyOn( window, 'getSelection' ).mockReturnValue( {
		isCollapsed: false,
		toString: () => 'Bet',
	} );
	fireEvent.click( screen.getByText( '5' ) );
	vi.restoreAllMocks();
	unmount();

	renderViews( { onClickItem, isItemClickable: () => false } );
	fireEvent.click( screen.getByText( '5' ) );

	expect( onClickItem ).not.toHaveBeenCalled();
} );

test( 'the title of a row is the same node after the screen renders again', () => {
	const { rerender } = renderViews( { onClickItem: vi.fn() } );
	const title = screen.getByText( 'Alpha' );

	rerender( views( { onClickItem: vi.fn() } ) );

	expect( screen.getByText( 'Alpha' ) ).toBe( title );
} );

test( 'a click on the title keeps a text selection made in its row', () => {
	const onClickItem = vi.fn();
	const onChangeSelection = vi.fn();
	renderViews( {
		onClickItem,
		selection: [],
		onChangeSelection,
		actions: [
			{ id: 'go', label: 'Go', supportsBulk: true, callback: vi.fn() },
		],
	} );
	const alpha = screen.getByText( 'Alpha' );

	selectTextIn( alpha.firstChild );
	fireEvent.click( alpha );
	expect( onClickItem ).not.toHaveBeenCalled();

	// Ctrl-clic : le geste de sélection de DataViews reste le sien.
	fireEvent.click( alpha, { ctrlKey: true } );
	expect( onChangeSelection ).toHaveBeenCalledWith( [ '1' ] );
	expect( onClickItem ).not.toHaveBeenCalled();

	// Une sélection dans une autre ligne n'empêche pas d'ouvrir celle-ci.
	selectTextIn( screen.getByText( 'Beta' ).firstChild );
	fireEvent.click( alpha );
	expect( onClickItem ).toHaveBeenCalledTimes( 1 );
	expect( onClickItem ).toHaveBeenCalledWith( DATA[ 0 ] );
} );

test( 'rows are marked clickable only with onClickItem', () => {
	const { container, unmount } = renderViews();
	expect(
		container.querySelector( '.msradar-dataviews__layout' )
	).not.toHaveClass( 'is-clickable' );
	unmount();

	const second = renderViews( { onClickItem: vi.fn() } );
	expect(
		second.container.querySelector( '.msradar-dataviews__layout' )
	).toHaveClass( 'is-clickable' );
} );

test( 'the bulk actions bar appears above the table once a row is checked', () => {
	const onClickItem = vi.fn();
	const { container } = renderViews( {
		onClickItem,
		actions: [
			{ id: 'go', label: 'Go', supportsBulk: true, callback: vi.fn() },
		],
	} );
	expect(
		container.querySelector( '.dataviews-bulk-actions-footer__container' )
	).toBeNull();

	fireEvent.click(
		container.querySelectorAll( 'tbody input[type="checkbox"]' )[ 0 ]
	);

	const bars = container.querySelectorAll(
		'.dataviews-bulk-actions-footer__container'
	);
	expect( bars ).toHaveLength( 1 );
	expect( bars[ 0 ].closest( '.msradar-dataviews__bulk' ) ).not.toBeNull();
	const table = container.querySelector( 'table' );
	expect(
		// eslint-disable-next-line no-bitwise -- compareDocumentPosition returns a bit mask.
		bars[ 0 ].compareDocumentPosition( table ) &
			window.Node.DOCUMENT_POSITION_FOLLOWING
	).toBeTruthy();
	expect( onClickItem ).not.toHaveBeenCalled();
} );

test( 'the search, the header and the pagination stay in place', () => {
	const { container, unmount } = renderViews( {
		searchLabel: 'Search things',
		header: <button type="button">Export</button>,
	} );
	expect(
		screen.getByRole( 'searchbox', { name: 'Search things' } )
	).toBeInTheDocument();
	expect(
		screen.getByRole( 'button', { name: 'Export' } )
	).toBeInTheDocument();
	expect(
		container.querySelector( '.msradar-dataviews__footer' )
	).toBeNull();
	unmount();

	const paged = renderViews( {
		paginationInfo: { totalItems: 40, totalPages: 2 },
	} );
	expect(
		paged.container.querySelector( '.msradar-dataviews__footer' )
	).not.toBeNull();
} );
