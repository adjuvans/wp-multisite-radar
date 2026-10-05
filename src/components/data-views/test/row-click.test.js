import { afterEach, expect, test, vi } from 'vitest';
import { ITEM_ATTRIBUTE, rowItemId } from '../row-click';

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
				<td><span role="button" tabindex="0"><span ${ ITEM_ATTRIBUTE }="42"><span class="name">Blog RH</span></span></span></td>
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
