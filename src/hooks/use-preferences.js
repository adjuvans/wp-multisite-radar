import apiFetch from '@wordpress/api-fetch';
import { useDispatch } from '@wordpress/data';
import { useCallback, useEffect, useMemo, useRef } from '@wordpress/element';
import { STORE_NAME, toResponse } from '../store';
import { buildPath } from '../store/paths';
import { useResource } from './use-resource';

export const PREFERENCES_PATH = buildPath( '/preferences' );

export const DEFAULT_PREFERENCES = {
	sites: { fields: [], layout: 'table', per_page: 20 },
	plugins: { fields: [], per_page: 20 },
	themes: { fields: [], per_page: 20 },
	users: { fields: [], per_page: 20 },
	alerts: { fields: [], per_page: 20 },
};

function plain( value ) {
	return value && typeof value === 'object' && ! Array.isArray( value )
		? value
		: {};
}

export function mergePreferences( stored ) {
	const value = plain( stored );
	return Object.fromEntries(
		Object.entries( DEFAULT_PREFERENCES ).map( ( [ view, defaults ] ) => [
			view,
			{ ...defaults, ...plain( value[ view ] ) },
		] )
	);
}

/**
 * Préférences d'affichage de l'utilisateur. Une lecture en échec donne les valeurs par défaut ; un enregistrement
 * en échec est silencieux : la vue affichée reste telle que l'utilisateur l'a réglée.
 */
export function usePreferences() {
	const { data, error } = useResource( PREFERENCES_PATH );
	const { receiveResponse } = useDispatch( STORE_NAME );

	const save = useCallback(
		async ( view, patch ) => {
			try {
				const saved = await apiFetch( {
					path: PREFERENCES_PATH,
					method: 'POST',
					data: { [ view ]: patch },
				} );
				receiveResponse( PREFERENCES_PATH, toResponse( saved ) );
			} catch {
				// Sans conséquence pour la vue affichée.
			}
		},
		[ receiveResponse ]
	);

	const prefs = useMemo( () => {
		if ( data ) {
			return mergePreferences( data );
		}
		return error ? mergePreferences( {} ) : null;
	}, [ data, error ] );

	return { prefs, save };
}

/**
 * Enregistre les préférences d'une vue après un temps de repos (redimensionnement de colonnes, clics répétés).
 *
 * @param {( view: string, patch: Object ) => Promise} save  save() de usePreferences().
 * @param {string}                                     view  Vue (clé de DEFAULT_PREFERENCES).
 * @param {number}                                     delay Délai en millisecondes.
 */
export function useDebouncedSave( save, view, delay = 500 ) {
	const timer = useRef();
	useEffect( () => () => clearTimeout( timer.current ), [] );
	return useCallback(
		( patch ) => {
			clearTimeout( timer.current );
			timer.current = setTimeout( () => save( view, patch ), delay );
		},
		[ save, view, delay ]
	);
}
