import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { getConfig } from '../../admin/config';
import { DataForm, useFormValidity } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import { useResource } from '../../hooks/use-resource';
import { STORE_NAME, toResponse } from '../../store';
import { buildPath } from '../../store/paths';
import {
	ALL_FORM,
	changes,
	getSettingsFields,
	MENU_FORM,
	mergeDeep,
	SCAN_FORM,
} from './fields';

const SETTINGS_PATH = buildPath( '/settings' );

export default function SettingsView() {
	const settings = useResource( SETTINGS_PATH );
	const [ edits, setEdits ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const { receiveResponse, invalidate } = useDispatch( STORE_NAME );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const fields = useMemo( () => getSettingsFields( getConfig() ), [] );
	const data = useMemo(
		() => mergeDeep( settings.data || {}, edits ),
		[ settings.data, edits ]
	);
	const { validity, isValid } = useFormValidity( data, fields, ALL_FORM );
	const changed = useMemo(
		() => changes( settings.data || {}, data ),
		[ settings.data, data ]
	);
	const dirty = Object.keys( changed ).length > 0;

	const onChange = ( patch ) =>
		setEdits( ( current ) => mergeDeep( current, patch ) );

	const save = async () => {
		setSaving( true );
		try {
			const saved = await apiFetch( {
				path: SETTINGS_PATH,
				method: 'POST',
				data: changed,
			} );
			receiveResponse( SETTINGS_PATH, toResponse( saved ) );
			setEdits( {} );
			invalidate( buildPath( '/sites' ) );
			createSuccessNotice( __( 'Settings saved.', 'multisite-radar' ), {
				type: 'snackbar',
			} );
		} catch ( error ) {
			createErrorNotice(
				error?.message ||
					__( 'The settings could not be saved.', 'multisite-radar' ),
				{
					type: 'snackbar',
				}
			);
		} finally {
			setSaving( false );
		}
	};

	return (
		<form
			className="msradar-settings"
			onSubmit={ ( event ) => {
				event.preventDefault();
				save();
			} }
		>
			<ErrorNotice error={ settings.error } onRetry={ settings.retry } />
			{ settings.data && (
				<>
					<Card>
						<CardHeader>
							<h2>{ __( 'Analysis', 'multisite-radar' ) }</h2>
						</CardHeader>
						<CardBody>
							<DataForm
								data={ data }
								fields={ fields }
								form={ SCAN_FORM }
								validity={ validity }
								onChange={ onChange }
							/>
						</CardBody>
					</Card>
					<Card>
						<CardHeader>
							<h2>
								{ __(
									'Network sites menu',
									'multisite-radar'
								) }
							</h2>
						</CardHeader>
						<CardBody>
							<DataForm
								data={ data }
								fields={ fields }
								form={ MENU_FORM }
								validity={ validity }
								onChange={ onChange }
							/>
						</CardBody>
					</Card>
					<p>
						<Button
							variant="primary"
							type="submit"
							isBusy={ saving }
							disabled={ saving || ! dirty || ! isValid }
						>
							{ __( 'Save settings', 'multisite-radar' ) }
						</Button>
					</p>
				</>
			) }
		</form>
	);
}
