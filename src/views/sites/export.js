import { addQueryArgs } from '@wordpress/url';
import { getConfig } from '../../admin/config';

/**
 * Colonnes de l'export (clés de Export\SitesColumns) pour chaque champ visible de la liste.
 */
const FIELD_COLUMNS = {
	theme: [ 'theme' ],
	users_count: [ 'users_count', 'admins_count' ],
	content_count: [ 'content_count' ],
	media_count: [ 'media_count' ],
	disk_bytes: [ 'disk_bytes' ],
	db_bytes: [ 'db_bytes' ],
	last_activity_gmt: [ 'last_activity_gmt' ],
	alert_level: [ 'alert_level', 'alerts_count', 'alert_rules' ],
	rule: [ 'alert_rules' ],
	status: [ 'status' ],
	registry_status: [ 'registry_status' ],
	scanned_at_gmt: [ 'scanned_at_gmt' ],
};

export function exportColumns( fields ) {
	const columns = [ 'id', 'name', 'url' ];
	( fields || [] ).forEach( ( field ) => {
		( FIELD_COLUMNS[ field ] || [] ).forEach( ( column ) => {
			if ( ! columns.includes( column ) ) {
				columns.push( column );
			}
		} );
	} );
	return columns;
}

/**
 * Lien de téléchargement (admin-post.php, nonce msradar_export) avec les filtres de la vue.
 *
 * @param {string}   format  csv ou json.
 * @param {Object}   state   État de la vue Sites.
 * @param {string[]} fields  Champs visibles.
 * @param {number[]} include Sélection (vide : toute la liste filtrée).
 */
export function exportUrl( format, state, fields, include = [] ) {
	const { exportUrl: base, exportNonce } = getConfig();
	const args = {
		action: 'msradar_export',
		_wpnonce: exportNonce,
		resource: 'sites',
		format,
		fields: exportColumns( fields ).join( ',' ),
		orderby: state.orderby,
		order: state.order,
	};
	if ( state.search ) {
		args.search = state.search;
	}
	[ 'alert_level', 'status', 'registry_status' ].forEach( ( key ) => {
		if ( state[ key ].length > 0 ) {
			args[ key ] = state[ key ].join( ',' );
		}
	} );
	if ( state.rule ) {
		args.rule = state.rule;
	}
	if ( include.length > 0 ) {
		args.include = include.join( ',' );
	}
	return addQueryArgs( base, args );
}
