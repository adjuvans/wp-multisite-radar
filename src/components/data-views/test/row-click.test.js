import { afterEach, expect, test, vi } from 'vitest';
import { ITEM_ATTRIBUTE, keepsTextSelection, rowItemId } from '../row-click';

afterEach( () => {
	vi.restoreAllMocks();
	document.body.innerHTML = '';
} );

function setUp() {
	document.body.innerHTML = `
		<table><tbody>
			<tr class="dataviews-view-table__group-header-row"><td class="group">Inactive site</td></tr>
			<tr class="dataviews-view-table__row">
				<td><input type="checkbox" class="check" /></td>
				<td><span role="button" tabindex="0" class="dataviews-title-field"><span ${ ITEM_ATTRIBUTE }="42"><span class="name">Blog RH</span></span></span></td>
				<td class="cell">12</td>
				<td><a href="#site" class="link">Visit</a><button type="button" class="menu">Actions</button></td>
			</tr>
		</tbody></table>
		<p class="outside">Outside</p>`;
}

function click( selector, extra = {} ) {
	return {
		target: document.querySelector( selector ),
		button: 0,
		ctrlKey: false,
		metaKey: false,
		shiftKey: false,
		altKey: false,
		defaultPrevented: false,
		...extra,
	};
}

test( 'a click on a plain cell names the item of its row', () => {
	setUp();
	expect( rowItemId( click( '.cell' ) ) ).toBe( '42' );
} );

test( 'interactive elements keep their own behaviour', () => {
	setUp();
	for ( const selector of [ '.check', '.name', '.link', '.menu' ] ) {
		expect( rowItemId( click( selector ) ) ).toBeNull();
	}
} );

test( 'selection keys, other buttons and handled events open nothing', () => {
	setUp();
	for ( const extra of [
		{ ctrlKey: true },
		{ metaKey: true },
		{ shiftKey: true },
		{ altKey: true },
		{ button: 1 },
		{ defaultPrevented: true },
	] ) {
		expect( rowItemId( click( '.cell', extra ) ) ).toBeNull();
	}
} );

test( 'a group header or a click outside the rows opens nothing', () => {
	setUp();
	expect( rowItemId( click( '.group' ) ) ).toBeNull();
	expect( rowItemId( click( '.outside' ) ) ).toBeNull();
} );

test( 'selecting text in a row opens nothing', () => {
	setUp();
	vi.spyOn( window, 'getSelection' ).mockReturnValue( {
		isCollapsed: false,
		toString: () => 'Blog',
	} );
	expect( rowItemId( click( '.cell' ) ) ).toBeNull();
} );

/**
 * Sélection de texte simulée entre deux nœuds.
 *
 * @param {Node}   anchor Début.
 * @param {Node}   focus  Fin.
 * @param {Object} extra  Propriétés en plus.
 */
function select( anchor, focus = anchor, extra = {} ) {
	vi.spyOn( window, 'getSelection' ).mockReturnValue( {
		isCollapsed: false,
		toString: () => 'Blog',
		anchorNode: anchor,
		focusNode: focus,
		...extra,
	} );
}

test( 'a selection in the row stops a plain click on its title, not on its controls', () => {
	setUp();
	select( document.querySelector( '.name' ).firstChild );

	expect( keepsTextSelection( click( '.name' ) ) ).toBe( true );
	expect( keepsTextSelection( click( '.cell' ) ) ).toBe( true );
	for ( const selector of [ '.check', '.link', '.menu' ] ) {
		expect( keepsTextSelection( click( selector ) ) ).toBe( false );
	}
	for ( const extra of [
		{ ctrlKey: true },
		{ metaKey: true },
		{ shiftKey: true },
	] ) {
		expect( keepsTextSelection( click( '.name', extra ) ) ).toBe( false );
	}
} );

test( 'a click on the title goes through without a selection in its row', () => {
	setUp();
	expect( keepsTextSelection( click( '.name' ) ) ).toBe( false );

	const outside = document.querySelector( '.outside' ).firstChild;
	select( outside );
	expect( keepsTextSelection( click( '.name' ) ) ).toBe( false );

	// Le navigateur dit si la sélection traverse la ligne.
	vi.restoreAllMocks();
	select( outside, outside, {
		containsNode: ( node, partly ) => partly && node.matches( 'tr' ),
	} );
	expect( keepsTextSelection( click( '.name' ) ) ).toBe( true );
} );
