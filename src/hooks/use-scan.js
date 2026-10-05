import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';
import { useDispatch } from '@wordpress/data';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { STORE_NAME } from '../store';
import { buildPath, NAMESPACE } from '../store/paths';

const IDLE = {
	running: false,
	processed: 0,
	total: 0,
	remaining: 0,
	deferred: false,
};
const SPEAK_EVERY_MS = 5000;

function wait( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

/**
 * Analyse pilotée par l'interface : POST /scan marque les sites, puis POST /scan/batch traite des lots bornés
 * jusqu'à ce qu'il n'en reste plus. Un lot sans progrès (le cron détient le verrou) fait attendre puis réessayer ;
 * après maxWaits essais sans progrès, l'interface s'arrête et annonce que l'analyse continue en arrière-plan.
 * Une analyse ciblée ({ scope: 'ids', ids }) passe ses ids à chaque lot : seuls ces sites sont traités et comptés,
 * l'arriéré du réseau reste au cron. Une seule analyse à la fois : start() pendant qu'elle tourne renvoie sa promesse
 * et l'annonce par un avis, car DataViews 19.1 laisse « Analyse again » cliquable pendant une analyse.
 *
 * @param {Object} options          Réglages (raccourcis par les tests).
 * @param {number} options.waitMs   Attente entre deux lots sans progrès.
 * @param {number} options.maxWaits Lots consécutifs sans progrès avant d'abandonner.
 */
export function useScan( { waitMs = 3000, maxWaits = 20 } = {} ) {
	const [ progress, setProgress ] = useState( IDLE );
	const mounted = useRef( true );
	const active = useRef( null );
	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );
	const { invalidate } = useDispatch( STORE_NAME );
	const { createSuccessNotice, createInfoNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const update = useCallback( ( patch ) => {
		if ( mounted.current ) {
			setProgress( ( current ) => ( { ...current, ...patch } ) );
		}
	}, [] );

	const run = useCallback(
		async ( request ) => {
			const ids = request?.scope === 'ids' ? request.ids : null;
			update( { ...IDLE, running: true } );
			speak( __( 'Analysis started.', 'multisite-radar' ) );
			try {
				const marked = await apiFetch( {
					path: buildPath( '/scan' ),
					method: 'POST',
					data: request,
				} );
				// La réponse de POST /scan compte tout le réseau : une analyse ciblée ne suit que ses sites.
				let remaining = ids ? ids.length : marked.remaining;
				let processed = 0;
				let total = remaining;
				let waits = 0;
				let spokenAt = Date.now();
				update( { total, remaining } );

				while (
					mounted.current &&
					remaining > 0 &&
					waits <= maxWaits
				) {
					const batch = await apiFetch( {
						path: buildPath( '/scan/batch' ),
						method: 'POST',
						...( ids ? { data: { ids } } : {} ),
					} );
					processed += batch.processed;
					remaining = batch.remaining;
					total = Math.max( total, processed + remaining );
					update( { processed, total, remaining } );
					if ( batch.processed > 0 ) {
						waits = 0;
					} else {
						waits += 1;
						await wait( waitMs );
					}
					if ( Date.now() - spokenAt >= SPEAK_EVERY_MS ) {
						speak(
							sprintf(
								/* translators: 1: number of sites analysed, 2: number of sites to analyse. */
								__(
									'%1$d of %2$d sites analysed.',
									'multisite-radar'
								),
								processed,
								total
							)
						);
						spokenAt = Date.now();
					}
				}

				invalidate( NAMESPACE );
				const deferred = remaining > 0;
				const message = deferred
					? __(
							'The analysis continues in the background.',
							'multisite-radar'
						)
					: __( 'Analysis complete.', 'multisite-radar' );
				( deferred ? createInfoNotice : createSuccessNotice )(
					message,
					{ type: 'snackbar' }
				);
				speak( message );
				update( { running: false, deferred } );
			} catch ( error ) {
				// Les sites analysés avant l'échec doivent apparaître à jour.
				invalidate( NAMESPACE );
				createErrorNotice(
					error?.message ||
						__(
							'The analysis could not be run.',
							'multisite-radar'
						),
					{ type: 'snackbar' }
				);
				update( { running: false } );
			}
		},
		[
			update,
			waitMs,
			maxWaits,
			invalidate,
			createSuccessNotice,
			createInfoNotice,
			createErrorNotice,
		]
	);

	const start = useCallback(
		( request ) => {
			if ( active.current ) {
				createInfoNotice(
					__(
						'An analysis is already running. Select the sites again once it is complete.',
						'multisite-radar'
					),
					{ type: 'snackbar' }
				);
				return active.current;
			}
			active.current = run( request ).finally( () => {
				active.current = null;
			} );
			return active.current;
		},
		[ run, createInfoNotice ]
	);

	return { ...progress, start };
}
