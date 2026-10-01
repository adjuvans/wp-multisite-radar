import { filterValue, toFilters } from '../../components/data-views/filters';
import { buildPath } from '../../store/paths';
import {
	MAX_PAGE,
	page,
	perPage,
	RULE_PATTERN,
	subset,
	text,
	trimAscii,
} from '../../utils/view-query';

export const ORDERBY = [
	'id',
	'name',
	'last_activity',
	'users_count',
	'content_count',
	'media_count',
	'disk_bytes',
	'db_bytes',
	'alert_level',
	'scanned_at',
];
export const ALERT_LEVELS = [ 'none', 'info', 'warning', 'error' ];
export const STATUSES = [ 'public', 'private', 'archived', 'spam', 'deleted' ];
export const REGISTRY_STATUSES = [ 'fresh', 'stale', 'missing' ];
export const LAYOUTS = [ 'table', 'grid' ];

export const FIELD_TO_ORDERBY = {
	id: 'id',
	name: 'name',
	last_activity_gmt: 'last_activity',
	users_count: 'users_count',
	content_count: 'content_count',
	media_count: 'media_count',
	disk_bytes: 'disk_bytes',
	db_bytes: 'db_bytes',
	alert_level: 'alert_level',
	scanned_at_gmt: 'scanned_at',
};
export const ORDERBY_TO_FIELD = Object.fromEntries(
	Object.entries( FIELD_TO_ORDERBY ).map( ( [ field, key ] ) => [
		key,
		field,
	] )
);
export const DEFAULT_FIELDS = [
	'theme',
	'users_count',
	'content_count',
	'media_count',
	'last_activity_gmt',
	'alert_level',
	'scanned_at_gmt',
];

const LISTS = [ 'alert_level', 'status', 'registry_status' ];

export function parseSitesQuery( query ) {
	const orderby = text( query, 'orderby' );
	const rule = text( query, 'rule' );
	const layout = text( query, 'layout' );
	const site = text( query, 'site' );
	return {
		search: trimAscii( text( query, 's' ) ),
		page: page( query ),
		orderby: ORDERBY.includes( orderby ) ? orderby : 'name',
		order: text( query, 'order' ) === 'desc' ? 'desc' : 'asc',
		alert_level: subset( query, 'alert_level', ALERT_LEVELS ),
		status: subset( query, 'status', STATUSES ),
		registry_status: subset( query, 'registry_status', REGISTRY_STATUSES ),
		rule: RULE_PATTERN.test( rule ) ? rule : '',
		layout: LAYOUTS.includes( layout ) ? layout : '',
		site: /^\d+$/.test( site ) && Number( site ) > 0 ? Number( site ) : 0,
	};
}

export function sitesRestArgs( state, prefs ) {
	const args = {
		page: state.page,
		per_page: perPage( prefs?.sites?.per_page ),
		orderby: state.orderby,
		order: state.order,
	};
	if ( state.search ) {
		args.search = state.search;
	}
	LISTS.forEach( ( key ) => {
		if ( state[ key ].length > 0 ) {
			args[ key ] = state[ key ].join( ',' );
		}
	} );
	if ( state.rule ) {
		args.rule = state.rule;
	}
	return args;
}

export function sitesPath( state, prefs ) {
	return buildPath( '/sites', sitesRestArgs( state, prefs ) );
}

export function serializeSitesState( state ) {
	const out = {};
	if ( state.search ) {
		out.s = state.search;
	}
	if ( state.page > 1 ) {
		out.paged = String( state.page );
	}
	if ( state.orderby !== 'name' ) {
		out.orderby = state.orderby;
	}
	if ( state.order !== 'asc' ) {
		out.order = state.order;
	}
	LISTS.forEach( ( key ) => {
		if ( state[ key ].length > 0 ) {
			out[ key ] = state[ key ].join( ',' );
		}
	} );
	if ( state.rule ) {
		out.rule = state.rule;
	}
	if ( state.layout ) {
		out.layout = state.layout;
	}
	if ( state.site ) {
		out.site = String( state.site );
	}
	return out;
}

export function toSitesView( state, sitesPrefs ) {
	return {
		type: state.layout || sitesPrefs?.layout || 'table',
		search: state.search,
		page: state.page,
		perPage: perPage( sitesPrefs?.per_page ),
		sort: {
			field: ORDERBY_TO_FIELD[ state.orderby ] || 'name',
			direction: state.order,
		},
		filters: toFilters( [
			{
				field: 'alert_level',
				operator: 'isAny',
				value: state.alert_level,
			},
			{ field: 'status', operator: 'isAny', value: state.status },
			{
				field: 'registry_status',
				operator: 'isAny',
				value: state.registry_status,
			},
			{ field: 'rule', operator: 'is', value: state.rule },
		] ),
		titleField: 'name',
		fields: sitesPrefs?.fields?.length ? sitesPrefs.fields : DEFAULT_FIELDS,
		layout: {},
	};
}

function listFilter( filters, field, allowed ) {
	const value = filterValue( filters, field, [] );
	const given = Array.isArray( value ) ? value : [ value ];
	return allowed.filter( ( item ) => given.includes( item ) );
}

export function fromSitesView( view, current ) {
	const rule = String( filterValue( view.filters, 'rule', '' ) );
	return {
		...current,
		search: trimAscii( view.search || '' ),
		page: Math.max( 1, Math.min( MAX_PAGE, view.page || 1 ) ),
		orderby: FIELD_TO_ORDERBY[ view.sort?.field ] || 'name',
		order: view.sort?.direction === 'desc' ? 'desc' : 'asc',
		alert_level: listFilter( view.filters, 'alert_level', ALERT_LEVELS ),
		status: listFilter( view.filters, 'status', STATUSES ),
		registry_status: listFilter(
			view.filters,
			'registry_status',
			REGISTRY_STATUSES
		),
		rule: RULE_PATTERN.test( rule ) ? rule : '',
		layout: LAYOUTS.includes( view.type ) ? view.type : current.layout,
	};
}

export function sitesPrefsFromView( view ) {
	return {
		fields: view.fields || [],
		layout: LAYOUTS.includes( view.type ) ? view.type : 'table',
		per_page: perPage( view.perPage ),
	};
}
