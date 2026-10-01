import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback, useRef } from '@wordpress/element';
import { STORE_NAME } from '../store';

/**
 * Réponse d'une route REST, servie par le cache du store (préchargée par PHP, ou demandée une seule fois).
 * Pendant le chargement d'un nouveau chemin, la réponse précédente reste affichée (pas de scintillement) :
 * isFresh indique si data correspond bien au chemin demandé.
 *
 * @param {?string} path Chemin construit par buildPath(), ou null tant qu'il n'est pas connu.
 */
export function useResource( path ) {
	const { response, error } = useSelect(
		( select ) => ( {
			response: select( STORE_NAME ).getResponse( path ),
			error: select( STORE_NAME ).getError( path ),
		} ),
		[ path ]
	);
	const last = useRef( null );
	if ( response ) {
		last.current = response;
	}
	const { retry } = useDispatch( STORE_NAME );
	const onRetry = useCallback( () => {
		if ( path ) {
			retry( path );
		}
	}, [ path, retry ] );

	const shown = response || ( error ? null : last.current );
	return {
		data: shown ? shown.data : null,
		total: shown ? shown.total : null,
		totalPages: shown ? shown.totalPages : null,
		error,
		isLoading: !! path && ! response && ! error,
		isFresh: !! response,
		retry: onRetry,
	};
}
