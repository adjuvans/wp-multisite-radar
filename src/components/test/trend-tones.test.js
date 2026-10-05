import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test } from 'vitest';

// Les tests tournent depuis la racine du dépôt (import.meta.url n'est pas un fichier sous jsdom).
const scss = readFileSync(
	resolve( process.cwd(), 'src/admin/style.scss' ),
	'utf8'
);
const start = scss.indexOf( '$msradar-tones:' );
const tones = scss.slice( start, scss.indexOf( ');', start ) );

function channel( value ) {
	const c = value / 255;
	return c <= 0.03928 ? c / 12.92 : ( ( c + 0.055 ) / 1.055 ) ** 2.4;
}

function luminance( hex ) {
	const [ r, g, b ] = [ 1, 3, 5 ].map( ( index ) =>
		channel( parseInt( hex.slice( index, index + 2 ), 16 ) )
	);
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

test.each( [ 'info', 'warning', 'error' ] )(
	'the %s tone of the charts has a contrast of at least 3:1 on white (WCAG 1.4.11)',
	( tone ) => {
		const match = new RegExp( `${ tone }: (#[0-9a-f]{6})`, 'i' ).exec(
			tones
		);
		expect( match ).not.toBeNull();
		expect(
			1.05 / ( luminance( match[ 1 ] ) + 0.05 )
		).toBeGreaterThanOrEqual( 3 );
	}
);
