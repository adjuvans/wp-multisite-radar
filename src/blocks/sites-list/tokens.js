/**
 * Sites proposés par PHP (SitesMenu\Block::editor_data()).
 */
export function sitesFromWindow() {
	return Array.isArray( window.msradarSitesList )
		? window.msradarSitesList
		: [];
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
