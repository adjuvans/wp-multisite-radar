import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Erreur REST avec un bouton « Retry ».
 *
 * @param {Object}      props
 * @param {?Object}     props.error   { message } renvoyé par le store.
 * @param {?() => void} props.onRetry Relance de la requête.
 */
export default function ErrorNotice( { error, onRetry } ) {
	if ( ! error ) {
		return null;
	}
	const actions = onRetry
		? [ { label: __( 'Retry', 'multisite-radar' ), onClick: onRetry } ]
		: [];
	return (
		<Notice
			status="error"
			isDismissible={ false }
			actions={ actions }
			className="msradar-error"
		>
			{ error.message }
		</Notice>
	);
}
