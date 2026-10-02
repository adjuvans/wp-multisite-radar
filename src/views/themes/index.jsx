import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import InventoryView from '../inventory';
import {
	DEFAULT_THEME_FIELDS,
	getThemesFields,
	THEME_EXPORT_COLUMNS,
} from './fields';

/**
 * Sur un site dont le thème actif est un enfant de ce thème, le dit.
 *
 * @param {Object} theme Thème du panneau.
 * @param {Object} site  Site de la liste.
 */
function describeSite( theme, site ) {
	const active = site.theme?.stylesheet;
	return active && active !== theme.stylesheet
		? sprintf(
				/* translators: %s: folder of the active child theme. */
				__( 'Parent of the active theme %s', 'multisite-radar' ),
				active
			)
		: null;
}

export default function ThemesView() {
	const fields = useMemo( () => getThemesFields(), [] );
	return (
		<InventoryView
			resource="themes"
			fields={ fields }
			defaultFields={ DEFAULT_THEME_FIELDS }
			exportColumns={ THEME_EXPORT_COLUMNS }
			labels={ {
				search: __( 'Search themes', 'multisite-radar' ),
				empty: __( 'No theme matches this view.', 'multisite-radar' ),
				loading: __( 'Loading themes…', 'multisite-radar' ),
			} }
			describeSite={ describeSite }
		/>
	);
}
