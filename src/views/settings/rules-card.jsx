import { Card, CardBody, CardHeader, PanelBody } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { DataForm } from '../../components/data-views';
import ErrorNotice from '../../components/error-notice';
import Skeleton from '../../components/skeleton';
import { getRuleFields, ruleConfig, ruleForm } from './rule-fields';

function RulePanel( { rule, data, validity, onChange } ) {
	const fields = useMemo( () => getRuleFields( rule ), [ rule ] );
	const form = useMemo( () => ruleForm( rule ), [ rule ] );
	const title = ruleConfig( data, rule ).enabled
		? rule.label
		: sprintf(
				/* translators: %s: name of an alert rule. */
				__( '%s (disabled)', 'multisite-radar' ),
				rule.label
			);
	return (
		<PanelBody title={ title } initialOpen={ false }>
			{ rule.description && <p>{ rule.description }</p> }
			<DataForm
				data={ data }
				fields={ fields }
				form={ form }
				validity={ validity }
				onChange={ onChange }
			/>
		</PanelBody>
	);
}

/**
 * Section « Règles d'alertes » des réglages, construite depuis GET /alert-rules (spec §6.2) : un panneau par règle.
 *
 * @param {Object}   props
 * @param {Object}   props.resource Réponse de useResource( '/alert-rules' ).
 * @param {Object}   props.data     Réglages affichés, modifications comprises.
 * @param {Object}   props.validity Validité des champs (useFormValidity).
 * @param {Function} props.onChange Reçoit les modifications partielles.
 */
export default function RulesCard( { resource, data, validity, onChange } ) {
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Alert rules', 'multisite-radar' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Saving recomputes the alerts of every site in the background, from the data of their last analysis.',
						'multisite-radar'
					) }
				</p>
				<ErrorNotice
					error={ resource.error }
					onRetry={ resource.retry }
				/>
				{ ! resource.data && ! resource.error && (
					<Skeleton
						label={ __(
							'Loading alert rules…',
							'multisite-radar'
						) }
					/>
				) }
				{ ( resource.data || [] ).map( ( rule ) => (
					<RulePanel
						key={ rule.id }
						rule={ rule }
						data={ data }
						validity={ validity }
						onChange={ onChange }
					/>
				) ) }
			</CardBody>
		</Card>
	);
}
