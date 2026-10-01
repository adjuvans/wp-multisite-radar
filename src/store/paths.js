/**
 * Chemins REST canoniques : clés triées, valeurs vides omises, valeurs encodées avec encodeURIComponent.
 *
 * Ces chemins servent de clés au cache du store. PHP précharge les mêmes requêtes (Admin\Preload), mais les encode
 * avec rawurlencode : normalizePath() ramène les deux écritures à la même clé.
 */
export const NAMESPACE = '/multisite-radar/v1';

function isEmpty( value ) {
	return value === undefined || value === null || value === '';
}

export function buildPath( route, args = {} ) {
	const keys = Object.keys( args )
		.filter( ( key ) => ! isEmpty( args[ key ] ) )
		.sort();
	if ( keys.length === 0 ) {
		return `${ NAMESPACE }${ route }`;
	}
	const query = keys
		.map(
			( key ) =>
				`${ encodeURIComponent( key ) }=${ encodeURIComponent(
					String( args[ key ] )
				) }`
		)
		.join( '&' );
	return `${ NAMESPACE }${ route }?${ query }`;
}

function decode( part ) {
	try {
		return decodeURIComponent( part.replace( /\+/g, ' ' ) );
	} catch {
		return part;
	}
}

export function normalizePath( path ) {
	const index = path.indexOf( '?' );
	if ( index === -1 ) {
		return path;
	}
	const pairs = path
		.slice( index + 1 )
		.split( '&' )
		.filter( Boolean )
		.map( ( pair ) => {
			const eq = pair.indexOf( '=' );
			return eq === -1
				? [ decode( pair ), '' ]
				: [
						decode( pair.slice( 0, eq ) ),
						decode( pair.slice( eq + 1 ) ),
					];
		} );
	if ( pairs.length === 0 ) {
		return path.slice( 0, index );
	}
	pairs.sort( ( a, b ) => {
		if ( a[ 0 ] === b[ 0 ] ) {
			return 0;
		}
		return a[ 0 ] < b[ 0 ] ? -1 : 1;
	} );
	return `${ path.slice( 0, index ) }?${ pairs
		.map(
			( [ key, value ] ) =>
				`${ encodeURIComponent( key ) }=${ encodeURIComponent( value ) }`
		)
		.join( '&' ) }`;
}
