import { filterValue, toFilters } from '../../components/data-views/filters';
import { buildPath } from '../../store/paths';
import {
	MAX_PAGE,
	page,
	perPage,
	text,
	trimAscii,
} from '../../utils/view-query';

/**
 * État de la page Utilisateurs (adresse de la page) et arguments REST, avec les règles de Admin\ViewQuery::users() :
 * tests/fixtures/view-queries.json vérifie la parité.
 */
export const USER_ORDERBY = [
	'login',
	'display_name',
	'email',
	'sites_count',
	'published',
	'registered',
];
export const MEMBERSHIPS = [ 'none', 'several' ];
export const SUPER_ADMINS = 'yes';
export const DEFAULT_USER_FIELDS = [
	'display_name',
	'email',
	'super_admin',
	'sites_count',
	'registered_gmt',
];
export const FIELD_TO_ORDERBY = {
	login: 'login',
	display_name: 'display_name',
	email: 'email',
	sites_count: 'sites_count',
	published: 'published',
	registered_gmt: 'registered',
};
export const ORDERBY_TO_FIELD = Object.fromEntries(
	Object.entries( FIELD_TO_ORDERBY ).map( ( [ field, key ] ) => [
		key,
		field,
	] )
);

export function parseUsersQuery( query ) {
	const orderby = text( query, 'orderby' );
	const membership = text( query, 'membership' );
	const user = text( query, 'user' );
	return {
		search: trimAscii( text( query, 's' ) ),
		page: page( query ),
		orderby: USER_ORDERBY.includes( orderby ) ? orderby : 'login',
		order: text( query, 'order' ) === 'desc' ? 'desc' : 'asc',
		membership: MEMBERSHIPS.includes( membership ) ? membership : '',
		super_admin: text( query, 'super_admin' ) === '1',
		user: /^\d+$/.test( user ) && Number( user ) > 0 ? Number( user ) : 0,
	};
}

export function usersRestArgs( state, prefs ) {
	const args = {
		page: state.page,
		per_page: perPage( prefs?.users?.per_page ),
		orderby: state.orderby,
		order: state.order,
	};
	if ( state.search ) {
		args.search = state.search;
	}
	if ( state.membership ) {
		args.membership = state.membership;
	}
	if ( state.super_admin ) {
		args.super_admin = 1;
	}
	return args;
}

export function usersPath( state, prefs ) {
	return buildPath( '/users', usersRestArgs( state, prefs ) );
}

export function serializeUsersState( state ) {
	const out = {};
	if ( state.search ) {
		out.s = state.search;
	}
	if ( state.page > 1 ) {
		out.paged = String( state.page );
	}
	if ( state.orderby !== 'login' ) {
		out.orderby = state.orderby;
	}
	if ( state.order !== 'asc' ) {
		out.order = state.order;
	}
	if ( state.membership ) {
		out.membership = state.membership;
	}
	if ( state.super_admin ) {
		out.super_admin = '1';
	}
	if ( state.user ) {
		out.user = String( state.user );
	}
	return out;
}

/**
 * Vue DataViews de la liste des comptes.
 *
 * @param {Object}      state      État de la page.
 * @param {Object|null} usersPrefs Préférences enregistrées.
 * @param {string[]}    available  Identifiants des colonnes proposées (sans E-mail pour qui ne peut pas le voir) ; null : toutes.
 */
export function toUsersView( state, usersPrefs, available = null ) {
	return {
		type: 'table',
		search: state.search,
		page: state.page,
		perPage: perPage( usersPrefs?.per_page ),
		sort: {
			field: ORDERBY_TO_FIELD[ state.orderby ] || 'login',
			direction: state.order,
		},
		filters: toFilters( [
			{ field: 'sites_count', operator: 'is', value: state.membership },
			{
				field: 'super_admin',
				operator: 'is',
				value: state.super_admin ? SUPER_ADMINS : '',
			},
		] ),
		titleField: 'login',
		fields: ( usersPrefs?.fields?.length
			? usersPrefs.fields
			: DEFAULT_USER_FIELDS
		).filter( ( id ) => ! available || available.includes( id ) ),
		layout: {},
	};
}

export function fromUsersView( view, current ) {
	const membership = String( filterValue( view.filters, 'sites_count', '' ) );
	return {
		...current,
		search: trimAscii( view.search || '' ),
		page: Math.max( 1, Math.min( MAX_PAGE, view.page || 1 ) ),
		orderby: FIELD_TO_ORDERBY[ view.sort?.field ] || 'login',
		order: view.sort?.direction === 'desc' ? 'desc' : 'asc',
		membership: MEMBERSHIPS.includes( membership ) ? membership : '',
		super_admin:
			filterValue( view.filters, 'super_admin', '' ) === SUPER_ADMINS,
	};
}

export function usersPrefsFromView( view ) {
	return { fields: view.fields || [], per_page: perPage( view.perPage ) };
}
