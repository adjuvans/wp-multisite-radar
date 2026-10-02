import { addQueryArgs } from '@wordpress/url';
import { getConfig } from '../admin/config';

/**
 * Lien de téléchargement d'un export (admin-post.php, nonce msradar_export, Export\ExportHandler).
 *
 * @param {string} resource sites, plugins ou themes.
 * @param {string} format   csv ou json.
 * @param {Object} args     Filtres, tri et colonnes, aux noms de la route REST de la ressource.
 */
export function exportLink( resource, format, args = {} ) {
	const { exportUrl, exportNonce } = getConfig();
	return addQueryArgs( exportUrl, {
		action: 'msradar_export',
		_wpnonce: exportNonce,
		resource,
		format,
		...args,
	} );
}

/**
 * Colonnes d'un export : les colonnes fixes, puis celles de chaque champ visible, sans doublon.
 *
 * @param {string[]}                 fixed   Colonnes toujours exportées.
 * @param {Object<string, string[]>} byField Champ de la liste => colonnes d'export.
 * @param {string[]}                 fields  Champs visibles.
 */
export function columnsFor( fixed, byField, fields ) {
	const columns = [ ...fixed ];
	( fields || [] ).forEach( ( field ) => {
		( byField[ field ] || [] ).forEach( ( column ) => {
			if ( ! columns.includes( column ) ) {
				columns.push( column );
			}
		} );
	} );
	return columns;
}
