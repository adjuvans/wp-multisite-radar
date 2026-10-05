import { useCallback, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { info, pencil } from '@wordpress/icons';
import { getConfig } from '../../admin/config';
import { DataViews } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import Skeleton from '../../components/skeleton';
import { useDebouncedSave, usePreferences } from '../../hooks/use-preferences';
import { useResource } from '../../hooks/use-resource';
import { useUrlState } from '../../hooks/use-url-state';
import { samePrefs } from '../../utils/view-query';
import UserPanel from '../user-panel';
import { getUsersFields } from './fields';
import {
	fromUsersView,
	parseUsersQuery,
	serializeUsersState,
	toUsersView,
	usersPath,
	usersPrefsFromView,
} from './query';

export default function UsersView() {
	const { canSeeEmails } = getConfig();
	const [ state, setState ] = useUrlState(
		parseUsersQuery,
		serializeUsersState
	);
	const { prefs, save } = usePreferences();
	const savePrefs = useDebouncedSave( save, 'users' );
	const [ localPrefs, setLocalPrefs ] = useState( null );
	const usersPrefs = localPrefs || prefs?.users || null;

	const list = useResource(
		usersPrefs ? usersPath( state, { users: usersPrefs } ) : null
	);
	const fields = useMemo(
		() => getUsersFields( { canSeeEmails } ),
		[ canSeeEmails ]
	);
	const available = useMemo(
		() => fields.map( ( field ) => field.id ),
		[ fields ]
	);
	const view = useMemo(
		() => toUsersView( state, usersPrefs, available ),
		[ state, usersPrefs, available ]
	);
	const openUser = useCallback(
		( item ) =>
			setState( ( current ) => ( { ...current, user: item.id } ) ),
		[ setState ]
	);
	const actions = useMemo(
		() => [
			{
				id: 'open',
				label: __( 'View the account', 'multisite-radar' ),
				icon: info,
				callback: ( [ item ] ) => openUser( item ),
			},
			{
				id: 'edit',
				label: __( 'Edit the account', 'multisite-radar' ),
				icon: pencil,
				callback: ( [ item ] ) =>
					window.location.assign( item.edit_url ),
			},
		],
		[ openUser ]
	);
	const openRow = ( list.data || [] ).find(
		( item ) => item.id === state.user
	);

	const onChangeView = ( next ) => {
		setState( ( current ) => fromUsersView( next, current ) );
		const nextPrefs = usersPrefsFromView( next );
		if ( ! samePrefs( nextPrefs, usersPrefs ) ) {
			setLocalPrefs( nextPrefs );
			savePrefs( nextPrefs );
		}
	};

	return (
		<div className="msradar-users">
			<ErrorNotice error={ list.error } onRetry={ list.retry } />
			<DataViews
				data={ list.data || [] }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				onClickItem={ openUser }
				defaultLayouts={ { table: {} } }
				paginationInfo={ {
					totalItems: list.total || 0,
					totalPages: list.totalPages || 0,
				} }
				isLoading={ false }
				getItemId={ ( item ) => String( item.id ) }
				searchLabel={ __( 'Search users', 'multisite-radar' ) }
				empty={
					list.isLoading ? (
						<Skeleton
							lines={ 5 }
							label={ __( 'Loading users…', 'multisite-radar' ) }
						/>
					) : (
						<p className="msradar-empty">
							{ __(
								'No user matches this view.',
								'multisite-radar'
							) }
						</p>
					)
				}
			/>
			{ state.user > 0 && (
				<UserPanel
					userId={ state.user }
					title={
						openRow
							? openRow.display_name || openRow.login
							: __( 'Account', 'multisite-radar' )
					}
					onClose={ () =>
						setState( ( current ) => ( { ...current, user: 0 } ) )
					}
				/>
			) }
		</div>
	);
}
