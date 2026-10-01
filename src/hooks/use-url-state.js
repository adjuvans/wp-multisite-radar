import { useEffect, useRef, useState } from '@wordpress/element';
import { addQueryArgs, getQueryArgs } from '@wordpress/url';

export function readQuery() {
	return getQueryArgs( window.location.href );
}

/**
 * État d'une vue synchronisé avec l'adresse de la page (recherche, filtres, tri, page, présentation, site ouvert) :
 * un lien copié ou un rechargement rouvre la même vue. Le paramètre « page » de WordPress est conservé.
 *
 * @param {( query: Object ) => Object} parse     Paramètres d'URL → état.
 * @param {( state: Object ) => Object} serialize État → paramètres d'URL, sans les valeurs par défaut.
 */
export function useUrlState( parse, serialize ) {
	const [ state, setState ] = useState( () => parse( readQuery() ) );
	const serializer = useRef( serialize );
	serializer.current = serialize;

	useEffect( () => {
		const { page } = readQuery();
		const next = addQueryArgs( window.location.pathname, {
			...( page ? { page } : {} ),
			...serializer.current( state ),
		} );
		if ( next !== window.location.pathname + window.location.search ) {
			window.history.replaceState( window.history.state, '', next );
		}
	}, [ state ] );

	return [ state, setState ];
}
