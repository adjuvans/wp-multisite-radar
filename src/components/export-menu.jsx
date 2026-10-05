import { DropdownMenu } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { download } from '@wordpress/icons';

/**
 * Menu « Export » d'une liste : CSV ou JSON. Le bouton porte son libellé à côté de l'icône (spec rc.2 § 3.3).
 *
 * @param {Object}                     props
 * @param {(format: string) => string} props.href URL de téléchargement pour csv ou json.
 */
export default function ExportMenu( { href } ) {
	const label = __( 'Export', 'multisite-radar' );
	return (
		<DropdownMenu
			icon={ download }
			label={ label }
			text={ label }
			toggleProps={ { showTooltip: false } }
			controls={ [
				{
					title: __( 'Export as CSV', 'multisite-radar' ),
					onClick: () => window.location.assign( href( 'csv' ) ),
				},
				{
					title: __( 'Export as JSON', 'multisite-radar' ),
					onClick: () => window.location.assign( href( 'json' ) ),
				},
			] }
		/>
	);
}
