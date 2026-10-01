/**
 * Lecture des paramètres d'URL des vues, avec exactement les règles de PHP (Admin\ViewQuery) :
 * la requête préchargée par PHP doit être celle que le client construit. tests/fixtures/view-queries.json
 * vérifie la parité des deux côtés.
 */
export const PER_PAGE = [ 10, 20, 50, 100 ];
export const MAX_PAGE = 100000;
export const RULE_PATTERN = /^[a-z0-9_]{1,40}$/;

export function text( query, key ) {
	const value = query?.[ key ];
	return typeof value === 'string' || Number.isInteger( value )
		? String( value )
		: '';
}

/**
 * Comme trim() de PHP : espaces, tabulations, retours, NUL et tabulation verticale seulement
 * (une espace insécable est conservée, contrairement à String.prototype.trim()).
 *
 * @param {string} value Texte.
 */
export function trimAscii( value ) {
	return value.replace( /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '' );
}

/**
 * Chiffres seulement (« 1e3 » n'est pas une page), bornés à MAX_PAGE.
 *
 * @param {Object} query Paramètres d'URL.
 */
export function page( query ) {
	const value = text( query, 'paged' );
	if ( ! /^\d+$/.test( value ) ) {
		return 1;
	}
	return Math.max( 1, Math.min( MAX_PAGE, Number( value ) ) );
}

export function perPage( value ) {
	return PER_PAGE.includes( value ) ? value : 20;
}

/**
 * Valeurs autorisées présentes dans une liste séparée par des virgules, dans l'ordre de allowed.
 *
 * @param {Object}   query   Paramètres d'URL.
 * @param {string}   key     Paramètre.
 * @param {string[]} allowed Valeurs autorisées, dans l'ordre canonique.
 */
export function subset( query, key, allowed ) {
	const given = text( query, key ).split( ',' ).map( trimAscii );
	return allowed.filter( ( value ) => given.includes( value ) );
}

/**
 * Deux préférences d'affichage (présentation, par page, champs) sont-elles identiques ?
 *
 * @param {Object} a Préférences.
 * @param {Object} b Préférences.
 */
export function samePrefs( a, b ) {
	return (
		!! a &&
		!! b &&
		a.layout === b.layout &&
		a.per_page === b.per_page &&
		JSON.stringify( a.fields ) === JSON.stringify( b.fields )
	);
}
