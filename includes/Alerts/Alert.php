<?php
namespace MultisiteRadar\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Une alerte levée par une règle. Le message est construit à la lecture, dans la langue de l'utilisateur.
 */
final class Alert {

	public string $rule;
	public string $severity;
	public array $args;

	public function __construct( string $rule, string $severity, array $args = [] ) {
		$this->rule     = $rule;
		$this->severity = $severity;
		$this->args     = $args;
	}

	/**
	 * @return array{rule: string, severity: string, args: array}
	 */
	public function to_array(): array {
		return [
			'rule'     => $this->rule,
			'severity' => $this->severity,
			'args'     => $this->args,
		];
	}

	public static function from_array( array $data ): ?self {
		if ( ! isset( $data['rule'], $data['severity'] ) || ! is_string( $data['rule'] ) || ! Severity::is_valid( (string) $data['severity'] ) ) {
			return null;
		}
		return new self( $data['rule'], (string) $data['severity'], is_array( $data['args'] ?? null ) ? $data['args'] : [] );
	}
}
