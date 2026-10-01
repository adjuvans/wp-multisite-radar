#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * Version du plugin : MSRADAR_VERSION (multisite-radar.php) est la seule source de vérité (spec §2.2).
 *
 *     node bin/version.mjs check       vérifie que toutes les copies sont identiques (CI) ;
 *     node bin/version.mjs set 2.0.0   écrit la version partout.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const SOURCE = {
	file: 'multisite-radar.php',
	pattern: /(define\( 'MSRADAR_VERSION', ')([^']+)(' \);)/,
};
const COPIES = [
	{ file: 'multisite-radar.php', pattern: /^( \* Version:\s+)(\S+)()$/m },
	{ file: 'readme.txt', pattern: /^(Stable tag:\s+)(\S+)()$/m },
	{ file: 'package.json', pattern: /^(\s*"version": ")([^"]+)(",?)$/m },
	{
		file: 'tests/phpstan-bootstrap.php',
		pattern: /(define\( 'MSRADAR_VERSION', ')([^']+)(' \);)/,
	},
];
const VERSION_PATTERN = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/;

function read( target ) {
	const content = readFileSync( join( ROOT, target.file ), 'utf8' );
	const match = content.match( target.pattern );
	if ( ! match ) {
		throw new Error( `${ target.file }: version not found` );
	}
	return { content, version: match[ 2 ] };
}

function check() {
	const expected = read( SOURCE ).version;
	const wrong = COPIES.map( ( target ) => ( {
		file: target.file,
		version: read( target ).version,
	} ) ).filter( ( copy ) => copy.version !== expected );
	wrong.forEach( ( copy ) =>
		console.error(
			`${ copy.file }: ${ copy.version } (expected ${ expected })`
		)
	);
	if ( wrong.length > 0 ) {
		process.exitCode = 1;
		return;
	}
	console.log( `Version ${ expected } is consistent.` );
}

function set( version ) {
	if ( ! VERSION_PATTERN.test( version || '' ) ) {
		console.error( 'Usage: node bin/version.mjs set <x.y.z[-pre]>' );
		process.exitCode = 1;
		return;
	}
	for ( const target of [ SOURCE, ...COPIES ] ) {
		const { content } = read( target );
		writeFileSync(
			join( ROOT, target.file ),
			content.replace(
				target.pattern,
				( match, before, previous, after ) =>
					`${ before }${ version }${ after }`
			)
		);
	}
	console.log( `Version set to ${ version }.` );
}

const [ command, value ] = process.argv.slice( 2 );
if ( command === 'check' ) {
	check();
} else if ( command === 'set' ) {
	set( value );
} else {
	console.error( 'Usage: node bin/version.mjs check | set <version>' );
	process.exitCode = 1;
}
