import { __, sprintf } from '@wordpress/i18n';
import { formatNumber } from '../../utils/format';
import { UPDATE_AVAILABLE } from './query';

/**
 * Nom d'un plugin ou d'un thème, avec son fichier ou son dossier en dessous.
 *
 * @param {Object} props
 * @param {string} props.name   Nom.
 * @param {string} props.detail Fichier ou dossier.
 */
export function ExtensionTitle( { name, detail } ) {
	return (
		<span className="msradar-site-title">
			<span className="msradar-site-title__name">{ name }</span>
			<span className="msradar-site-title__url">{ detail }</span>
		</span>
	);
}

export function versionField() {
	return {
		id: 'version',
		type: 'text',
		label: __( 'Version', 'multisite-radar' ),
		enableSorting: false,
		filterBy: false,
		render: ( { item } ) => item.version || '—',
	};
}

/**
 * Statut avec son badge.
 *
 * @param {Object<string, string>} labels Statut => libellé, dans l'ordre des filtres.
 * @param {Object<string, string>} tones  Statut => ton du badge (error, warning, info) ; neutre sinon.
 */
export function statusField( labels, tones ) {
	return {
		id: 'status',
		type: 'text',
		label: __( 'Status', 'multisite-radar' ),
		enableSorting: false,
		elements: Object.entries( labels ).map( ( [ value, label ] ) => ( {
			value,
			label,
		} ) ),
		filterBy: { operators: [ 'isAny' ] },
		render: ( { item } ) => (
			<span
				className={
					tones[ item.status ]
						? `msradar-badge msradar-badge--${ tones[ item.status ] }`
						: 'msradar-badge'
				}
			>
				{ labels[ item.status ] || item.status }
			</span>
		),
	};
}

/**
 * Nombre de sites, triable.
 *
 * @param {?Function} render Rendu particulier ({ item }) => contenu.
 */
export function sitesCountField( render = null ) {
	return {
		id: 'sites_count',
		type: 'integer',
		label: __( 'Sites', 'multisite-radar' ),
		filterBy: false,
		render: render || ( ( { item } ) => formatNumber( item.sites_count ) ),
	};
}

export function updateField() {
	return {
		id: 'update_version',
		type: 'text',
		label: __( 'Update', 'multisite-radar' ),
		enableSorting: false,
		elements: [
			{
				value: UPDATE_AVAILABLE,
				label: __( 'Update available', 'multisite-radar' ),
			},
		],
		filterBy: { operators: [ 'is' ] },
		getValue: ( { item } ) =>
			item.update_version ? UPDATE_AVAILABLE : '',
		render: ( { item } ) =>
			item.update_version ? (
				<span className="msradar-badge msradar-badge--warning">
					{ sprintf(
						/* translators: %s: version number. */
						__( 'Version %s available', 'multisite-radar' ),
						item.update_version
					) }
				</span>
			) : (
				'—'
			),
	};
}
