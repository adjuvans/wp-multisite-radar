import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { pencil } from '@wordpress/icons';
import { DataViews } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import Skeleton from '../../components/skeleton';
import { useDebouncedSave, usePreferences } from '../../hooks/use-preferences';
import { useResource } from '../../hooks/use-resource';
import { useUrlState } from '../../hooks/use-url-state';
import { samePrefs } from '../../utils/view-query';
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
	const fields = useMemo( () => getUsersFields(), [] );
	const view = useMemo(
		() => toUsersView( state, usersPrefs ),
		[ state, usersPrefs ]
	);
	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit the account', 'multisite-radar' ),
				icon: pencil,
				isPrimary: true,
				callback: ( [ item ] ) =>
					window.location.assign( item.edit_url ),
			},
		],
		[]
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
		</div>
	);
}
