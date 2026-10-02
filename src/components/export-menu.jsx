import { DropdownMenu } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { download } from '@wordpress/icons';

/**
 * Menu « Export » d'une liste : CSV ou JSON.
 *
 * @param {Object}                     props
 * @param {(format: string) => string} props.href URL de téléchargement pour csv ou json.
 */
export default function ExportMenu( { href } ) {
	return (
		<DropdownMenu
			icon={ download }
			label={ __( 'Export', 'multisite-radar' ) }
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
