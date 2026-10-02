import { __ } from '@wordpress/i18n';
import { formatNumber } from '../../utils/format';
import { DateCell } from '../sites/fields';
import { SUPER_ADMINS } from './query';

export function membershipLabels() {
	return {
		none: __( 'No site', 'multisite-radar' ),
		several: __( 'Several sites', 'multisite-radar' ),
	};
}

/**
 * Valeur de filtre d'un compte : aucun site, un site, plusieurs.
 *
 * @param {number} count Nombre de sites.
 */
function membership( count ) {
	if ( count === 0 ) {
		return 'none';
	}
	return count > 1 ? 'several' : 'one';
}

/**
 * Champs DataViews de la liste des comptes. Les filtres « Sites » et « Super admin » sont appliqués par la route REST.
 */
export function getUsersFields() {
	const memberships = membershipLabels();
	return [
		{
			id: 'login',
			type: 'text',
			label: __( 'Login', 'multisite-radar' ),
			enableHiding: false,
			filterBy: false,
		},
		{
			id: 'display_name',
			type: 'text',
			label: __( 'Name', 'multisite-radar' ),
			filterBy: false,
		},
		{
			id: 'super_admin',
			type: 'text',
			label: __( 'Super admin', 'multisite-radar' ),
			enableSorting: false,
			elements: [
				{
					value: SUPER_ADMINS,
					label: __( 'Super admins only', 'multisite-radar' ),
				},
			],
			filterBy: { operators: [ 'is' ] },
			getValue: ( { item } ) => ( item.super_admin ? SUPER_ADMINS : '' ),
			render: ( { item } ) =>
				item.super_admin ? (
					<span className="msradar-badge">
						{ __( 'Super admin', 'multisite-radar' ) }
					</span>
				) : (
					'—'
				),
		},
		{
			id: 'sites_count',
			type: 'text',
			label: __( 'Sites', 'multisite-radar' ),
			elements: Object.entries( memberships ).map(
				( [ value, label ] ) => ( { value, label } )
			),
			filterBy: { operators: [ 'is' ] },
			getValue: ( { item } ) => membership( item.sites_count ),
			render: ( { item } ) =>
				item.sites_count === 0 ? (
					<span className="msradar-badge msradar-badge--warning">
						{ memberships.none }
					</span>
				) : (
					formatNumber( item.sites_count )
				),
		},
		{
			id: 'registered_gmt',
			type: 'datetime',
			label: __( 'Registered', 'multisite-radar' ),
			filterBy: false,
			render: ( { item } ) => <DateCell value={ item.registered_gmt } />,
		},
	];
}
