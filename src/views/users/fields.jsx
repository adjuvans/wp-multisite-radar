import { __, _n, sprintf } from '@wordpress/i18n';
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
 * Rôles d'un compte, du plus fréquent au moins fréquent : « Administrator on 3 sites, Editor on 1 site ».
 *
 * @param {Array} roles Liste de { label, sites } (GET /users).
 */
export function rolesSummary( roles = [] ) {
	return roles
		.map( ( role ) =>
			sprintf(
				/* translators: 1: role name, 2: number of sites. */
				_n(
					'%1$s on %2$d site',
					'%1$s on %2$d sites',
					role.sites,
					'multisite-radar'
				),
				role.label,
				role.sites
			)
		)
		.join( ', ' );
}

/**
 * Champs DataViews de la liste des comptes. Les filtres « Sites » et « Super admin » sont appliqués par la route REST.
 *
 * @param {Object}  options
 * @param {boolean} options.canSeeEmails Le compte connecté peut voir les e-mails.
 */
export function getUsersFields( { canSeeEmails = false } = {} ) {
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
			label: __( 'Public name', 'multisite-radar' ),
			filterBy: false,
		},
		...( canSeeEmails
			? [
					{
						id: 'email',
						type: 'text',
						label: __( 'Email', 'multisite-radar' ),
						filterBy: false,
						render: ( { item } ) => item.email || '—',
					},
				]
			: [] ),
		{
			id: 'full_name',
			type: 'text',
			label: __( 'First and last name', 'multisite-radar' ),
			enableSorting: false,
			filterBy: false,
			getValue: ( { item } ) =>
				[ item.first_name, item.last_name ]
					.filter( Boolean )
					.join( ' ' ),
			render: ( { item } ) =>
				[ item.first_name, item.last_name ]
					.filter( Boolean )
					.join( ' ' ) || '—',
		},
		{
			id: 'roles',
			type: 'text',
			label: __( 'Roles', 'multisite-radar' ),
			enableSorting: false,
			filterBy: false,
			getValue: ( { item } ) => rolesSummary( item.roles ),
			render: ( { item } ) => rolesSummary( item.roles ) || '—',
		},
		{
			id: 'published',
			type: 'integer',
			label: __( 'Published content', 'multisite-radar' ),
			filterBy: false,
			render: ( { item } ) =>
				item.published === null || item.published === undefined
					? '—'
					: formatNumber( item.published ),
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
