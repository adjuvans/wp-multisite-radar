#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * Lit le rapport de « wp plugin check … --format=csv --fields=file,line,type,code,message » (make plugin-check) et
 * échoue sur la moindre erreur ou le moindre avertissement, comme le mode strict de la CI.
 * Usage : node bin/plugin-check-report.mjs <rapport.csv>
 */
import { readFileSync } from 'node:fs';

/**
 * Lignes CSV (guillemets doublés, retours à la ligne dans un champ guillemeté).
 *
 * @param {string} text Contenu du rapport.
 * @return {string[][]} Lignes.
 */
export function parseCsv( text ) {
	const rows = [];
	let row = [];
	let field = '';
	let quoted = false;
	for ( let i = 0; i < text.length; i++ ) {
		const char = text[ i ];
		if ( quoted ) {
			if ( char === '"' && text[ i + 1 ] === '"' ) {
				field += '"';
				i++;
			} else if ( char === '"' ) {
				quoted = false;
			} else {
				field += char;
			}
		} else if ( char === '"' ) {
			quoted = true;
		} else if ( char === ',' ) {
			row.push( field );
			field = '';
		} else if ( char === '\n' ) {
			row.push( field );
			rows.push( row );
			row = [];
			field = '';
		} else if ( char !== '\r' ) {
			field += char;
		}
	}
	if ( field !== '' || row.length ) {
		row.push( field );
		rows.push( row );
	}
	return rows;
}

const file = process.argv[ 2 ];
if ( ! file ) {
	console.error( 'Usage: node bin/plugin-check-report.mjs <report.csv>' );
	process.exit( 2 );
}
const rows = parseCsv( readFileSync( file, 'utf8' ) );
const ran = rows.some(
	( row ) =>
		row[ 0 ].startsWith( 'Success:' ) ||
		row.join( ',' ) === 'file,line,type,code,message'
);
if ( ! ran ) {
	console.error(
		`Unexpected Plugin Check output in ${ file }: is wp-env started?`
	);
	process.exit( 2 );
}
const issues = rows.filter(
	( row ) => row.length >= 5 && [ 'ERROR', 'WARNING' ].includes( row[ 2 ] )
);
for ( const [ path, line, type, code, message ] of issues ) {
	console.log(
		`${ path.replace( /^.*?\/(includes|build|languages|uninstall\.php|multisite-radar\.php|readme\.txt)/, '$1' ) }:${ line } ${ type } ${ code } ${ message }`
	);
}
const errors = issues.filter( ( row ) => row[ 2 ] === 'ERROR' ).length;
console.log(
	`Plugin Check: ${ errors } error(s), ${ issues.length - errors } warning(s).`
);
process.exit( issues.length ? 1 : 0 );
