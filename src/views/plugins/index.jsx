import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import InventoryView from '../inventory';
import {
	DEFAULT_PLUGIN_FIELDS,
	getPluginsFields,
	PLUGIN_EXPORT_COLUMNS,
} from './fields';

function panelNote( plugin ) {
	return plugin.network_active
		? __(
				'Network activated: every site of the network loads this plugin.',
				'multisite-radar'
			)
		: null;
}

export default function PluginsView() {
	const fields = useMemo( () => getPluginsFields(), [] );
	return (
		<InventoryView
			resource="plugins"
			fields={ fields }
			defaultFields={ DEFAULT_PLUGIN_FIELDS }
			exportColumns={ PLUGIN_EXPORT_COLUMNS }
			labels={ {
				search: __( 'Search plugins', 'multisite-radar' ),
				empty: __( 'No plugin matches this view.', 'multisite-radar' ),
				loading: __( 'Loading plugins…', 'multisite-radar' ),
			} }
			panelNote={ panelNote }
		/>
	);
}
