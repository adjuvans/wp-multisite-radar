<?php
namespace MultisiteRadar\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Transforme les alertes stockées (règle + arguments) en libellés et messages traduits.
 */
final class AlertFormatter {

	private RuleRegistry $rules;

	public function __construct( RuleRegistry $rules ) {
		$this->rules = $rules;
	}

	/**
	 * @param array $stored Contenu de data['alerts'].
	 * @return array<int, array{rule: string, severity: string, label: string, message: string}>
	 */
	public function format( array $stored ): array {
		$formatted = [];
		foreach ( $stored as $item ) {
			$alert = is_array( $item ) ? Alert::from_array( $item ) : null;
			if ( null === $alert ) {
				continue;
			}
			$rule        = $this->rules->get( $alert->rule );
			$formatted[] = [
				'rule'     => $alert->rule,
				'severity' => $alert->severity,
				'label'    => null !== $rule ? $rule->label() : $alert->rule,
				'message'  => null !== $rule ? $rule->message( $alert->args ) : $alert->rule,
			];
		}
		return $formatted;
	}
}
