import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { normalizePath } from './paths';

/**
 * Cache des réponses REST de l'application, indexé par chemin canonique (normalizePath).
 * L'état initial vient des données préchargées par PHP : la première vue s'affiche sans requête.
 */
export const STORE_NAME = 'msradar/core';

function headerValue( headers, name ) {
	const wanted = name.toLowerCase();
	const key = Object.keys( headers || {} ).find(
		( k ) => k.toLowerCase() === wanted
	);
	if ( key === undefined ) {
		return null;
	}
	const value = parseInt( headers[ key ], 10 );
	return Number.isNaN( value ) ? null : value;
}

export function toResponse( body, headers = {} ) {
	return {
		data: body,
		total: headerValue( headers, 'X-WP-Total' ),
		totalPages: headerValue( headers, 'X-WP-TotalPages' ),
	};
}

export function toError( error ) {
	return {
		code: error?.code || 'msradar_request_failed',
		message:
			error?.message ||
			__(
				'The request failed. Check your connection and try again.',
				'multisite-radar'
			),
		status: error?.data?.status ?? null,
	};
}

export function initialState( preload = {} ) {
	const responses = {};
	Object.entries( preload || {} ).forEach( ( [ path, entry ] ) => {
		if ( entry && typeof entry === 'object' && 'body' in entry ) {
			responses[ normalizePath( path ) ] = toResponse(
				entry.body,
				entry.headers
			);
		}
	} );
	return { responses, errors: {} };
}

function without( map, test ) {
	return Object.fromEntries(
		Object.entries( map ).filter( ( [ key ] ) => ! test( key ) )
	);
}

function reducer( state, action ) {
	switch ( action.type ) {
		case 'RECEIVE_RESPONSE':
			return {
				responses: {
					...state.responses,
					[ action.key ]: action.response,
				},
				errors: without( state.errors, ( key ) => key === action.key ),
			};
		case 'RECEIVE_ERROR':
			return {
				...state,
				errors: { ...state.errors, [ action.key ]: action.error },
			};
		case 'FORGET_PREFIX':
			return {
				responses: without( state.responses, ( key ) =>
					key.startsWith( action.prefix )
				),
				errors: without( state.errors, ( key ) =>
					key.startsWith( action.prefix )
				),
			};
		case 'FORGET_KEY':
			return {
				responses: without(
					state.responses,
					( key ) => key === action.key
				),
				errors: without( state.errors, ( key ) => key === action.key ),
			};
		default:
			return state;
	}
}

async function readError( error ) {
	// Avec parse: false, apiFetch rejette la réponse HTTP elle-même : son corps porte { code, message, data }.
	if ( error && typeof error.json === 'function' ) {
		try {
			return await error.json();
		} catch {
			return {};
		}
	}
	return error;
}

const actions = {
	receiveResponse: ( path, response ) => ( {
		type: 'RECEIVE_RESPONSE',
		key: normalizePath( path ),
		response,
	} ),
	receiveError: ( path, error ) => ( {
		type: 'RECEIVE_ERROR',
		key: normalizePath( path ),
		error,
	} ),
	invalidate:
		( prefix ) =>
		( { dispatch } ) => {
			dispatch( { type: 'FORGET_PREFIX', prefix } );
			dispatch.invalidateResolutionForStoreSelector( 'getResponse' );
		},
	retry:
		( path ) =>
		( { dispatch } ) => {
			dispatch( { type: 'FORGET_KEY', key: normalizePath( path ) } );
			dispatch.invalidateResolution( 'getResponse', [ path ] );
		},
};

const selectors = {
	getResponse: ( state, path ) =>
		path ? ( state.responses[ normalizePath( path ) ] ?? null ) : null,
	getError: ( state, path ) =>
		path ? ( state.errors[ normalizePath( path ) ] ?? null ) : null,
};

const resolvers = {
	getResponse: {
		isFulfilled: ( state, path ) =>
			! path || state.responses[ normalizePath( path ) ] !== undefined,
		fulfill:
			( path ) =>
			async ( { dispatch } ) => {
				try {
					const response = await apiFetch( { path, parse: false } );
					const body = await response.json();
					dispatch.receiveResponse(
						path,
						toResponse(
							body,
							Object.fromEntries( response.headers.entries() )
						)
					);
				} catch ( error ) {
					dispatch.receiveError(
						path,
						toError( await readError( error ) )
					);
				}
			},
	},
};

export function createCoreStore( preload = {} ) {
	const defaultState = initialState( preload );
	return createReduxStore( STORE_NAME, {
		reducer: ( state = defaultState, action ) => reducer( state, action ),
		actions,
		selectors,
		resolvers,
	} );
}

export function registerCoreStore( preload = {} ) {
	const store = createCoreStore( preload );
	register( store );
	return store;
}
