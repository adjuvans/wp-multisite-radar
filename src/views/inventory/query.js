import { filterValue, toFilters } from '../../components/data-views/filters';
import { buildPath, encodeSegments } from '../../store/paths';
import {
	MAX_PAGE,
	page,
	perPage,
	subset,
	text,
	trimAscii,
} from '../../utils/view-query';

/**
 * État des pages Plugins et Thèmes (adresse de la page) et arguments REST, avec exactement les règles de PHP
 * (Admin\ViewQuery::plugins() et ::themes()) : tests/fixtures/view-queries.json vérifie la parité.
 */
export const INVENTORY_ORDERBY = [ 'name', 'sites_count' ];
export const INVENTORY_STATUSES = {
	plugins: [ 'network', 'local', 'unused', 'missing' ],
	themes: [ 'used', 'unused', 'missing' ],
};
export const UPDATE_AVAILABLE = 'available';

export function parseInventoryQuery( query, statuses ) {
	const orderby = text( query, 'orderby' );
	return {
		search: trimAscii( text( query, 's' ) ),
		page: page( query ),
		orderby: INVENTORY_ORDERBY.includes( orderby ) ? orderby : 'name',
		order: text( query, 'order' ) === 'desc' ? 'desc' : 'asc',
		status: subset( query, 'status', statuses ),
		has_update: text( query, 'has_update' ) === '1',
	};
}

/**
 * Filtres et tri, communs à la liste et à l'export.
 *
 * @param {Object} state État de la vue.
 */
function filterArgs( state ) {
	const args = { orderby: state.orderby, order: state.order };
	if ( state.search ) {
		args.search = state.search;
	}
	if ( state.status.length > 0 ) {
		args.status = state.status.join( ',' );
	}
	if ( state.has_update ) {
		args.has_update = 1;
	}
	return args;
}

export function inventoryRestArgs( state, perPageValue ) {
	return {
		page: state.page,
		per_page: perPage( perPageValue ),
		...filterArgs( state ),
	};
}

export function inventoryPath( resource, state, viewPrefs ) {
	return buildPath(
		`/${ resource }`,
		inventoryRestArgs( state, viewPrefs?.per_page )
	);
}

/**
 * Chemin des sites d'un plugin (identifiant sans « .php ») ou d'un thème (dossier).
 *
 * @param {string} resource plugins ou themes.
 * @param {string} id       Identifiant public.
 * @param {Object} args     Pagination.
 */
export function extensionSitesPath( resource, id, args ) {
	return buildPath( `/${ resource }/${ encodeSegments( id ) }/sites`, args );
}

export function inventoryExportArgs( state, columns ) {
	return { ...filterArgs( state ), fields: columns.join( ',' ) };
}

export function serializeInventoryState( state ) {
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
	if ( state.status.length > 0 ) {
		out.status = state.status.join( ',' );
	}
	if ( state.has_update ) {
		out.has_update = '1';
	}
	return out;
}

export function toInventoryView( state, viewPrefs, defaultFields ) {
	return {
		type: 'table',
		search: state.search,
		page: state.page,
		perPage: perPage( viewPrefs?.per_page ),
		sort: { field: state.orderby, direction: state.order },
		filters: toFilters( [
			{ field: 'status', operator: 'isAny', value: state.status },
			{
				field: 'update_version',
				operator: 'is',
				value: state.has_update ? UPDATE_AVAILABLE : '',
			},
		] ),
		titleField: 'name',
		fields: viewPrefs?.fields?.length ? viewPrefs.fields : defaultFields,
		layout: {},
	};
}

export function fromInventoryView( view, current, statuses ) {
	const status = filterValue( view.filters, 'status', [] );
	const given = Array.isArray( status ) ? status : [ status ];
	return {
		...current,
		search: trimAscii( view.search || '' ),
		page: Math.max( 1, Math.min( MAX_PAGE, view.page || 1 ) ),
		orderby: INVENTORY_ORDERBY.includes( view.sort?.field )
			? view.sort.field
			: 'name',
		order: view.sort?.direction === 'desc' ? 'desc' : 'asc',
		status: statuses.filter( ( value ) => given.includes( value ) ),
		has_update:
			filterValue( view.filters, 'update_version', '' ) ===
			UPDATE_AVAILABLE,
	};
}

export function inventoryPrefsFromView( view ) {
	return { fields: view.fields || [], per_page: perPage( view.perPage ) };
}
