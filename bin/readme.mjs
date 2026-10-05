#!/usr/bin/env node
/* eslint-disable no-console */
/**
 * Vérifie readme.txt avant une publication sur WordPress.org (spec §11.4) : en-têtes, description courte (celle de
 * l'en-tête du plugin, 150 caractères au plus), sections, une légende par capture de .wordpress-org/, entrée du
 * journal pour la version en cours. Usage : node bin/readme.mjs (npm run readme:check).
 */
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const CONTRIBUTORS = 'adjuvans, cyrilledegourcy';
const SECTIONS = [
	'Description',
	'Installation',
	'Frequently Asked Questions',
	'Screenshots',
	'Changelog',
	'Upgrade Notice',
];

function field( text, name ) {
	const match = new RegExp( `^${ name }:[ \\t]*(.*)$`, 'm' ).exec( text );
	return match ? match[ 1 ].trim() : null;
}

function pluginField( plugin, name ) {
	const match = new RegExp( `^ \\* ${ name }:\\s+(.+)$`, 'm' ).exec( plugin );
	return match ? match[ 1 ].trim() : null;
}

function section( readme, name ) {
	const marker = `== ${ name } ==`;
	const start = readme.indexOf( marker );
	if ( start < 0 ) {
		return null;
	}
	const body = readme.slice( start + marker.length );
	const end = body.search( /^== /m );
	return end < 0 ? body : body.slice( 0, end );
}

function numbers( list ) {
	return list.join( ', ' ) || 'none';
}

/**
 * @param {string}   readme Contenu de readme.txt.
 * @param {string}   plugin Contenu de multisite-radar.php.
 * @param {string[]} files  Fichiers de .wordpress-org/.
 * @return {string[]} Erreurs ; vide si le readme est prêt.
 */
export function checkReadme( readme, plugin, files ) {
	const errors = [];
	const lines = readme.split( '\n' );
	if ( lines[ 0 ] !== '=== Multisite Radar ===' ) {
		errors.push( 'The first line must be "=== Multisite Radar ===".' );
	}
	if ( field( readme, 'Contributors' ) !== CONTRIBUTORS ) {
		errors.push( `Contributors must be "${ CONTRIBUTORS }".` );
	}
	const tags = ( field( readme, 'Tags' ) || '' )
		.split( ',' )
		.map( ( tag ) => tag.trim() )
		.filter( Boolean );
	if ( tags.length < 1 || tags.length > 5 ) {
		errors.push( `Tags: 1 to 5 are allowed, found ${ tags.length }.` );
	}
	for ( const name of [ 'Requires at least', 'Requires PHP' ] ) {
		if ( field( readme, name ) !== pluginField( plugin, name ) ) {
			errors.push(
				`${ name } is "${ field( readme, name ) }", the plugin header says "${ pluginField( plugin, name ) }".`
			);
		}
	}
	const tested = field( readme, 'Tested up to' ) || '';
	if ( ! /^\d+\.\d+$/.test( tested ) ) {
		errors.push(
			`Tested up to must be a major version such as 7.1, found "${ tested }".`
		);
	}
	const version = pluginField( plugin, 'Version' );
	if ( field( readme, 'Stable tag' ) !== version ) {
		errors.push(
			`Stable tag must be the version of the plugin, ${ version }.`
		);
	}
	if ( ! field( readme, 'License' ) || ! field( readme, 'License URI' ) ) {
		errors.push( 'License and License URI are required.' );
	}

	// La description courte : première ligne non vide après le bloc des en-têtes.
	const blank = lines.indexOf( '', 1 );
	const short =
		( blank < 0 ? [] : lines.slice( blank ) ).find( ( line ) =>
			line.trim()
		) || '';
	if ( short.length > 150 ) {
		errors.push(
			`The short description has ${ short.length } characters, 150 at most.`
		);
	} else if ( short !== pluginField( plugin, 'Description' ) ) {
		errors.push(
			'The short description must be the Description of the plugin header.'
		);
	}

	for ( const name of SECTIONS ) {
		if ( section( readme, name ) === null ) {
			errors.push( `The section "== ${ name } ==" is missing.` );
		}
	}

	const shots = files
		.map( ( file ) => /^screenshot-(\d+)\.png$/.exec( file ) )
		.filter( Boolean )
		.map( ( match ) => Number( match[ 1 ] ) )
		.sort( ( a, b ) => a - b );
	const expected = shots.map( ( _, index ) => index + 1 );
	if ( shots.join() !== expected.join() ) {
		errors.push(
			`Screenshots must be numbered from 1 without gaps, found ${ numbers( shots ) }.`
		);
	}
	const captions = ( section( readme, 'Screenshots' ) || '' )
		.split( '\n' )
		.filter( ( line ) => /^\d+\. \S/.test( line ) )
		.map( ( line ) => Number( line.split( '.' )[ 0 ] ) );
	if ( captions.join() !== shots.join() ) {
		errors.push(
			`One caption per screenshot: captions ${ numbers( captions ) }, files ${ numbers( shots ) }.`
		);
	}

	if (
		! ( section( readme, 'Changelog' ) || '' ).includes(
			`= ${ version } =`
		)
	) {
		errors.push( `The changelog has no entry for ${ version }.` );
	}
	return errors;
}

if ( process.argv[ 1 ] === fileURLToPath( import.meta.url ) ) {
	const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
	const assets = join( root, '.wordpress-org' );
	const errors = checkReadme(
		readFileSync( join( root, 'readme.txt' ), 'utf8' ),
		readFileSync( join( root, 'multisite-radar.php' ), 'utf8' ),
		existsSync( assets ) ? readdirSync( assets ) : []
	);
	errors.forEach( ( error ) => console.error( error ) );
	if ( errors.length ) {
		process.exit( 1 );
	}
	console.log( 'readme.txt is ready for WordPress.org.' );
}
