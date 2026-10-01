import { DropdownMenu } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { download } from '@wordpress/icons';
import { exportUrl } from './export';

export default function ExportMenu( { state, fields } ) {
	return (
		<DropdownMenu
			icon={ download }
			label={ __( 'Export', 'multisite-radar' ) }
			controls={ [
				{
					title: __( 'Export as CSV', 'multisite-radar' ),
					onClick: () =>
						window.location.assign(
							exportUrl( 'csv', state, fields )
						),
				},
				{
					title: __( 'Export as JSON', 'multisite-radar' ),
					onClick: () =>
						window.location.assign(
							exportUrl( 'json', state, fields )
						),
				},
			] }
		/>
	);
}
