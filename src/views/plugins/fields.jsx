import { __, _x } from '@wordpress/i18n';
import {
	ExtensionTitle,
	sitesCountField,
	statusField,
	updateField,
	versionField,
} from '../inventory/fields';

export const DEFAULT_PLUGIN_FIELDS = [
	'version',
	'status',
	'sites_count',
	'update_version',
];

/**
 * Colonnes de l'export (clés de Export\PluginsExport) : le nom et le fichier, puis celles des champs visibles.
 */
export const PLUGIN_EXPORT_COLUMNS = {
	fixed: [ 'name', 'file' ],
	byField: {
		version: [ 'version' ],
		status: [ 'status', 'network_active' ],
		sites_count: [ 'sites_count' ],
		update_version: [ 'update_version' ],
	},
};

export function pluginStatusLabels() {
	return {
		network: __( 'Network activated', 'multisite-radar' ),
		local: __( 'Active on some sites', 'multisite-radar' ),
		// Contexte : l'accord diffère selon la langue (« inutilisée » pour une extension, « inutilisé » pour un thème).
		unused: _x( 'Unused', 'plugin status', 'multisite-radar' ),
		missing: __( 'Not installed', 'multisite-radar' ),
	};
}

export function getPluginsFields() {
	return [
		{
			id: 'name',
			type: 'text',
			label: __( 'Plugin', 'multisite-radar' ),
			enableHiding: false,
			filterBy: false,
			render: ( { item } ) => (
				<ExtensionTitle name={ item.name } detail={ item.file } />
			),
		},
		versionField(),
		statusField( pluginStatusLabels(), {
			network: 'success',
			local: 'success',
			unused: 'warning',
			missing: 'error',
		} ),
		sitesCountField(),
		updateField(),
	];
}
