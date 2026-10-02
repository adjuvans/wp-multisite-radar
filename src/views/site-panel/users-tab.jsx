import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import ErrorNotice from '../../components/error-notice';
import { useResource } from '../../hooks/use-resource';
import { buildPath } from '../../store/paths';

export default function UsersTab( { siteId } ) {
	const [ page, setPage ] = useState( 1 );
	const users = useResource(
		buildPath( `/sites/${ siteId }/users`, { page, per_page: 20 } )
	);
	const pages = users.totalPages || 1;

	if ( users.error ) {
		return <ErrorNotice error={ users.error } onRetry={ users.retry } />;
	}
	if ( ! users.data ) {
		return (
			<p aria-busy="true">
				{ __( 'Loading users…', 'multisite-radar' ) }
			</p>
		);
	}
	if ( users.data.length === 0 ) {
		return (
			<p>
				{ __( 'No user is attached to this site.', 'multisite-radar' ) }
			</p>
		);
	}
	return (
		<>
			<table
				className="widefat striped msradar-table"
				aria-busy={ users.isLoading }
			>
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Login', 'multisite-radar' ) }
						</th>
						<th scope="col">{ __( 'Name', 'multisite-radar' ) }</th>
						<th scope="col">
							{ __( 'Roles', 'multisite-radar' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ users.data.map( ( user ) => (
						<tr key={ user.id }>
							<td>
								{ user.login }
								{ user.super_admin && (
									<>
										{ ' ' }
										<span className="msradar-badge">
											{ __(
												'Super admin',
												'multisite-radar'
											) }
										</span>
									</>
								) }
							</td>
							<td>{ user.display_name }</td>
							<td>
								{ ( user.role_names || user.roles ).join(
									', '
								) || '—' }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<div className="msradar-pager">
				<Button
					variant="secondary"
					disabled={ page <= 1 }
					onClick={ () => setPage( page - 1 ) }
				>
					{ __( 'Previous', 'multisite-radar' ) }
				</Button>
				<span>
					{ sprintf(
						/* translators: 1: current page, 2: number of pages. */
						__( 'Page %1$d of %2$d', 'multisite-radar' ),
						page,
						pages
					) }
				</span>
				<Button
					variant="secondary"
					disabled={ page >= pages }
					onClick={ () => setPage( page + 1 ) }
				>
					{ __( 'Next', 'multisite-radar' ) }
				</Button>
			</div>
		</>
	);
}
