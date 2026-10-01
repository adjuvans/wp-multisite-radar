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

export const ALERT_ORDERBY = [ 'rule', 'name', 'severity' ];
export const SEVERITIES = [ 'error', 'warning', 'info' ];
export const FIELD_TO_ORDERBY = {
	rule: 'rule',
	site: 'name',
	severity: 'severity',
};
export const ORDERBY_TO_FIELD = {
	rule: 'rule',
	name: 'site',
	severity: 'severity',
};
export const DEFAULT_FIELDS = [ 'severity', 'message' ];

/**
 * Identifiants de règles valides, sans doublon, triés : même résultat que Admin\ViewQuery::alerts().
 *
 * @param {string[]} values Identifiants donnés.
 */
function ruleList( values ) {
	return [
		...new Set(
			values
				.map( trimAscii )
				.filter( ( rule ) => RULE_PATTERN.test( rule ) )
		),
	].sort();
}

export function parseAlertsQuery( query ) {
	const orderby = text( query, 'orderby' );
	return {
		search: trimAscii( text( query, 's' ) ),
		page: page( query ),
		orderby: ALERT_ORDERBY.includes( orderby ) ? orderby : 'rule',
		order: text( query, 'order' ) === 'desc' ? 'desc' : 'asc',
		severity: subset( query, 'severity', SEVERITIES ),
		rule: ruleList( text( query, 'rule' ).split( ',' ) ),
	};
}

export function alertsRestArgs( state, prefs ) {
	const args = {
		page: state.page,
		per_page: perPage( prefs?.alerts?.per_page ),
		orderby: state.orderby,
		order: state.order,
	};
	if ( state.search ) {
		args.search = state.search;
	}
	if ( state.severity.length > 0 ) {
		args.severity = state.severity.join( ',' );
	}
	if ( state.rule.length > 0 ) {
		args.rule = state.rule.join( ',' );
	}
	return args;
}

export function alertsPath( state, prefs ) {
	return buildPath( '/alerts', alertsRestArgs( state, prefs ) );
}

export function serializeAlertsState( state ) {
	const out = {};
	if ( state.search ) {
		out.s = state.search;
	}
	if ( state.page > 1 ) {
		out.paged = String( state.page );
	}
	if ( state.orderby !== 'rule' ) {
		out.orderby = state.orderby;
	}
	if ( state.order !== 'asc' ) {
		out.order = state.order;
	}
	if ( state.severity.length > 0 ) {
		out.severity = state.severity.join( ',' );
	}
	if ( state.rule.length > 0 ) {
		out.rule = state.rule.join( ',' );
	}
	return out;
}

export function toAlertsView( state, alertsPrefs ) {
	return {
		type: 'table',
		search: state.search,
		page: state.page,
		perPage: perPage( alertsPrefs?.per_page ),
		sort: {
			field: ORDERBY_TO_FIELD[ state.orderby ],
			direction: state.order,
		},
		filters: toFilters( [
			{ field: 'severity', operator: 'isAny', value: state.severity },
			{ field: 'rule', operator: 'isAny', value: state.rule },
		] ),
		titleField: 'site',
		fields: alertsPrefs?.fields?.length
			? alertsPrefs.fields
			: DEFAULT_FIELDS,
		// DataViews regroupe les éléments de la page : cela n'a de sens que trié par règle.
		groupBy: state.orderby === 'rule' ? { field: 'rule' } : undefined,
		layout: {},
	};
}

export function fromAlertsView( view, current ) {
	const severity = filterValue( view.filters, 'severity', [] );
	const rules = filterValue( view.filters, 'rule', [] );
	return {
		...current,
		search: trimAscii( view.search || '' ),
		page: Math.max( 1, Math.min( MAX_PAGE, view.page || 1 ) ),
		orderby: FIELD_TO_ORDERBY[ view.sort?.field ] || 'rule',
		order: view.sort?.direction === 'desc' ? 'desc' : 'asc',
		severity: SEVERITIES.filter( ( value ) =>
			( Array.isArray( severity ) ? severity : [ severity ] ).includes(
				value
			)
		),
		rule: ruleList(
			( Array.isArray( rules ) ? rules : [ rules ] ).map( String )
		),
	};
}

export function alertsPrefsFromView( view ) {
	return { fields: view.fields || [], per_page: perPage( view.perPage ) };
}
