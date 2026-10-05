import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import ErrorNotice from '../../components/error-notice';
import SidePanel from '../../components/side-panel';
import Skeleton from '../../components/skeleton';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';
import { formatNumber } from '../../utils/format';
import { DateCell } from '../sites/fields';

/**
 * Fiche d'un compte (GET /users/{id}) : identité, e-mail si le compte connecté peut le voir, et ses sites avec son
 * rôle et ses contenus publiés sur chacun. Chaque site mène à son tableau de bord.
 *
 * @param {Object}     props
 * @param {number}     props.userId  Identifiant du compte.
 * @param {string}     props.title   Titre en attendant la fiche (nom de la ligne).
 * @param {() => void} props.onClose Ferme le panneau.
 */
export default function UserPanel( { userId, title, onClose } ) {
	const user = useResource( buildPath( `/users/${ userId }` ) );
	const data = user.data;
	const name = data ? data.display_name || data.login : title;
	const fullName = data
		? [ data.first_name, data.last_name ].filter( Boolean ).join( ' ' )
		: '';
	const more = data ? data.sites_total - data.sites.length : 0;

	return (
		<SidePanel
			title={ name }
			focusKey={ userId }
			onClose={ onClose }
			actions={
				data ? (
					<Button variant="secondary" href={ data.edit_url }>
						{ __( 'Edit the account', 'multisite-radar' ) }
					</Button>
				) : null
			}
		>
			<ErrorNotice error={ user.error } onRetry={ user.retry } />
			{ ! data && ! user.error && (
				<Skeleton
					lines={ 5 }
					label={ __( 'Loading the account…', 'multisite-radar' ) }
				/>
			) }
			{ data && (
				<>
					<dl className="msradar-facts">
						<dt>{ __( 'Login', 'multisite-radar' ) }</dt>
						<dd>{ data.login }</dd>
						{ fullName && (
							<>
								<dt>
									{ __(
										'First and last name',
										'multisite-radar'
									) }
								</dt>
								<dd>{ fullName }</dd>
							</>
						) }
						{ data.email !== undefined && (
							<>
								<dt>{ __( 'Email', 'multisite-radar' ) }</dt>
								<dd>{ data.email }</dd>
							</>
						) }
						<dt>{ __( 'Registered', 'multisite-radar' ) }</dt>
						<dd>
							<DateCell value={ data.registered_gmt } />
						</dd>
						<dt>{ __( 'Super admin', 'multisite-radar' ) }</dt>
						<dd>
							{ data.super_admin
								? __( 'Yes', 'multisite-radar' )
								: __( 'No', 'multisite-radar' ) }
						</dd>
						<dt>
							{ __( 'Published content', 'multisite-radar' ) }
						</dt>
						<dd>
							{ data.published === null
								? '—'
								: formatNumber( data.published ) }
						</dd>
					</dl>
					<h3>{ __( 'Sites', 'multisite-radar' ) }</h3>
					{ data.sites.length === 0 ? (
						<p>{ __( 'No site', 'multisite-radar' ) }</p>
					) : (
						<ul className="msradar-list">
							{ data.sites.map( ( site ) => (
								<li key={ site.id }>
									<a href={ site.admin_url }>{ site.name }</a>
									{ ' — ' }
									{ site.roles
										.map( ( role ) => role.label )
										.join( ', ' ) || '—' }
									{ site.published !== null &&
										` · ${ sprintf(
											/* translators: %s: number of published posts. */
											_n(
												'%s published',
												'%s published',
												site.published,
												'multisite-radar'
											),
											formatNumber( site.published )
										) }` }
								</li>
							) ) }
						</ul>
					) }
					{ more > 0 && (
						<p>
							{ sprintf(
								/* translators: %d: number of sites not listed. */
								_n(
									'And %d more site.',
									'And %d more sites.',
									more,
									'multisite-radar'
								),
								more
							) }
						</p>
					) }
				</>
			) }
		</SidePanel>
	);
}
