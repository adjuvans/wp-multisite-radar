import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { DataForm, useFormValidity } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import Skeleton from '../../components/skeleton';
import { useResource } from '../../hooks/use-resource';
import { STORE_NAME, toResponse } from '../../store';
import { buildPath } from '../../store/paths';
import { mergeDeep } from '../settings/fields';
import { DIGEST_FORM, digestChanges, getDigestFields } from './digest-fields';

const SETTINGS_PATH = buildPath( '/settings' );

/**
 * Réglage du récapitulatif hebdomadaire (écart E8 du plan M6), avec l'envoi d'un e-mail de test à soi-même.
 */
export default function DigestCard() {
	const settings = useResource( SETTINGS_PATH );
	const [ edits, setEdits ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const { receiveResponse } = useDispatch( STORE_NAME );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const fields = useMemo( () => getDigestFields(), [] );
	const data = useMemo(
		() => mergeDeep( settings.data || {}, edits ),
		[ settings.data, edits ]
	);
	const { validity, isValid } = useFormValidity( data, fields, DIGEST_FORM );
	const changed = useMemo(
		() => digestChanges( settings.data || {}, data ),
		[ settings.data, data ]
	);
	const dirty = Object.keys( changed ).length > 0;

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
			createSuccessNotice( __( 'Settings saved.', 'multisite-radar' ), {
				type: 'snackbar',
			} );
		} catch ( error ) {
			createErrorNotice(
				error?.message ||
					__( 'The settings could not be saved.', 'multisite-radar' ),
				{ type: 'snackbar' }
			);
		} finally {
			setSaving( false );
		}
	};

	const sendTest = async () => {
		setTesting( true );
		try {
			await apiFetch( {
				path: buildPath( '/reports/digest/test' ),
				method: 'POST',
			} );
			createSuccessNotice(
				__(
					'The test e-mail was sent to your address.',
					'multisite-radar'
				),
				{ type: 'snackbar' }
			);
		} catch ( error ) {
			createErrorNotice(
				error?.message ||
					__(
						'The test e-mail could not be sent.',
						'multisite-radar'
					),
				{ type: 'snackbar' }
			);
		} finally {
			setTesting( false );
		}
	};

	return (
		<Card className="msradar-digest">
			<CardHeader>
				<h2>{ __( 'Weekly summary', 'multisite-radar' ) }</h2>
			</CardHeader>
			<CardBody>
				<ErrorNotice
					error={ settings.error }
					onRetry={ settings.retry }
				/>
				{ ! settings.data && ! settings.error && (
					<Skeleton
						lines={ 3 }
						label={ __(
							'Loading the settings…',
							'multisite-radar'
						) }
					/>
				) }
				{ settings.data && (
					<form
						onSubmit={ ( event ) => {
							event.preventDefault();
							save();
						} }
					>
						<DataForm
							data={ data }
							fields={ fields }
							form={ DIGEST_FORM }
							validity={ validity }
							onChange={ ( patch ) =>
								setEdits( ( current ) =>
									mergeDeep( current, patch )
								)
							}
						/>
						<p className="msradar-digest__actions">
							<Button
								variant="primary"
								type="submit"
								isBusy={ saving }
								disabled={ saving || ! dirty || ! isValid }
							>
								{ __( 'Save', 'multisite-radar' ) }
							</Button>{ ' ' }
							<Button
								variant="secondary"
								isBusy={ testing }
								disabled={ testing }
								onClick={ sendTest }
							>
								{ __(
									'Send a test e-mail to me',
									'multisite-radar'
								) }
							</Button>
						</p>
					</form>
				) }
			</CardBody>
		</Card>
	);
}
