import { __ } from '@wordpress/i18n';
import { download, external, info, update, wordpress } from '@wordpress/icons';

/**
 * Actions de ligne et actions groupées de la liste des sites. « Analyse again » n'existe que pour msradar_manage.
 *
 * @param {Object}                    options
 * @param {boolean}                   options.canManage  Droit de lancer une analyse.
 * @param {boolean}                   options.isScanning Une analyse est en cours : « Analyse again » est désactivée.
 * @param {( item: Object ) => void}  options.onOpen     Ouvre la fiche d'un site.
 * @param {( ids: number[] ) => void} options.onRescan   Reçoit les identifiants à réanalyser.
 * @param {( ids: number[] ) => void} options.onExport   Reçoit les identifiants à exporter.
 */
export function getSitesActions( {
	canManage,
	isScanning = false,
	onOpen,
	onRescan,
	onExport,
} ) {
	const actions = [
		{
			id: 'open',
			label: __( 'View details', 'multisite-radar' ),
			icon: info,
			isPrimary: true,
			callback: ( [ item ] ) => onOpen( item ),
		},
		{
			id: 'admin',
			label: __( 'Site dashboard', 'multisite-radar' ),
			icon: wordpress,
			isEligible: ( item ) => !! item.admin_url,
			callback: ( [ item ] ) => window.location.assign( item.admin_url ),
		},
		{
			id: 'visit',
			label: __( 'Visit site', 'multisite-radar' ),
			icon: external,
			isEligible: ( item ) => !! item.url,
			callback: ( [ item ] ) =>
				window.open( item.url, '_blank', 'noopener,noreferrer' ),
		},
		{
			id: 'export',
			label: __( 'Export as CSV', 'multisite-radar' ),
			icon: download,
			supportsBulk: true,
			callback: ( items ) => onExport( items.map( ( item ) => item.id ) ),
		},
	];
	if ( canManage ) {
		actions.push( {
			id: 'rescan',
			label: __( 'Analyse again', 'multisite-radar' ),
			icon: update,
			supportsBulk: true,
			disabled: isScanning,
			callback: ( items ) => onRescan( items.map( ( item ) => item.id ) ),
		} );
	}
	return actions;
}
