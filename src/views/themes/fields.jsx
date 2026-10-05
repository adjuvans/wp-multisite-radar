import { __, _x, sprintf } from '@wordpress/i18n';
import { formatNumber } from '../../utils/format';
import {
	ExtensionTitle,
	sitesCountField,
	statusField,
	updateField,
	versionField,
} from '../inventory/fields';

export const DEFAULT_THEME_FIELDS = [
	'version',
	'parent',
	'allowed_on_network',
	'status',
	'sites_count',
	'update_version',
];

/**
 * Colonnes de l'export (clés de Export\ThemesExport) : le nom et le dossier, puis celles des champs visibles.
 */
export const THEME_EXPORT_COLUMNS = {
	fixed: [ 'name', 'stylesheet' ],
	byField: {
		version: [ 'version' ],
		parent: [ 'parent' ],
		allowed_on_network: [ 'allowed_on_network' ],
		status: [ 'status' ],
		sites_count: [ 'sites_count', 'active_count', 'parent_count' ],
		update_version: [ 'update_version' ],
	},
};

export function themeStatusLabels() {
	return {
		used: _x( 'Used', 'theme status', 'multisite-radar' ),
		unused: _x( 'Unused', 'theme status', 'multisite-radar' ),
		missing: __( 'Not installed', 'multisite-radar' ),
	};
}

export function getThemesFields() {
	return [
		{
			id: 'name',
			type: 'text',
			label: __( 'Theme', 'multisite-radar' ),
			enableHiding: false,
			filterBy: false,
			render: ( { item } ) => (
				<ExtensionTitle name={ item.name } detail={ item.stylesheet } />
			),
		},
		versionField(),
		{
			id: 'parent',
			type: 'text',
			label: __( 'Parent theme', 'multisite-radar' ),
			enableSorting: false,
			filterBy: false,
			render: ( { item } ) => item.parent || '—',
		},
		{
			id: 'allowed_on_network',
			type: 'text',
			label: __( 'Network enabled', 'multisite-radar' ),
			enableSorting: false,
			filterBy: false,
			render: ( { item } ) =>
				item.allowed_on_network
					? __( 'Yes', 'multisite-radar' )
					: __( 'No', 'multisite-radar' ),
		},
		statusField( themeStatusLabels(), {
			used: 'success',
			unused: 'warning',
			missing: 'error',
		} ),
		sitesCountField( ( { item } ) =>
			item.parent_count > 0
				? sprintf(
						/* translators: 1: number of sites, 2: number of those sites where the theme is the parent of the active theme. */
						__( '%1$s (%2$s as parent)', 'multisite-radar' ),
						formatNumber( item.sites_count ),
						formatNumber( item.parent_count )
					)
				: formatNumber( item.sites_count )
		),
		updateField(),
	];
}
