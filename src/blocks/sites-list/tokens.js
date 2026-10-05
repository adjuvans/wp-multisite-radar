import { addQueryArgs } from '@wordpress/url';

/**
 * Sites proposés par GET /sites-menu/sites (écart E4 du plan M7) : recherche par nom, ou noms des sites déjà choisis.
 */
export const SITES_PATH = '/multisite-radar/v1/sites-menu/sites';

/**
 * @param {Object}   query
 * @param {string}   query.search  Texte saisi.
 * @param {number[]} query.include Identifiants dont on veut le nom.
 * @return {string} Chemin pour apiFetch.
 */
export function sitesPath( { search = '', include = [] } = {} ) {
	return addQueryArgs( SITES_PATH, {
		...( search ? { search } : {} ),
		...( include.length ? { include: include.join( ',' ) } : {} ),
	} );
}

/**
 * Sites connus, sans doublon : un site déjà connu prend le nom le plus récent et garde sa place.
 *
 * @param {Array} current  Sites connus.
 * @param {Array} incoming Sites reçus.
 * @return {Array} Sites connus mis à jour.
 */
export function mergeSites( current, incoming ) {
	const merged = current.map(
		( site ) => incoming.find( ( item ) => item.id === site.id ) || site
	);
	incoming.forEach( ( site ) => {
		if ( ! merged.some( ( item ) => item.id === site.id ) ) {
			merged.push( site );
		}
	} );
	return merged;
}

export function tokenLabel( site ) {
	return `${ site.name || `#${ site.id }` } (#${ site.id })`;
}

export function idsToTokens( ids, sites ) {
	return ( ids || [] ).map( ( id ) => {
		const site = sites.find( ( item ) => item.id === id );
		return site ? tokenLabel( site ) : `#${ id }`;
	} );
}

/**
 * Jetons saisis → identifiants : nom exact, puis « Nom (#12) » ou « #12 » ; les jetons inconnus sont ignorés.
 * Le nom exact passe en premier : un site nommé « Team #5 » n'est pas le site 5.
 *
 * @param {Array} tokens Jetons de FormTokenField (chaînes ou { value }).
 * @param {Array} sites  Sites connus.
 */
export function tokensToIds( tokens, sites ) {
	const ids = [];
	( tokens || [] ).forEach( ( token ) => {
		const value = String(
			typeof token === 'string' ? token : ( token?.value ?? '' )
		).trim();
		const byName = sites.find( ( site ) => site.name === value )?.id;
		const match = /#(\d+)\)?$/.exec( value );
		const id = byName ?? ( match ? Number( match[ 1 ] ) : undefined );
		if ( id && ! ids.includes( id ) ) {
			ids.push( id );
		}
	} );
	return ids;
}
