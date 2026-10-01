<?php
namespace MultisiteRadar\Alerts;

use MultisiteRadar\Alerts\Rules\HighMediaRule;
use MultisiteRadar\Alerts\Rules\InactiveRule;
use MultisiteRadar\Alerts\Rules\NoUsersRule;

defined( 'ABSPATH' ) || exit;

/**
 * Règles disponibles. Le filtre msradar_alert_rules est appliqué à la première lecture,
 * et pas avant plugins_loaded, pour que les plugins tiers aient pu s'y abonner.
 */
final class RuleRegistry {

	/** @var RuleInterface[] */
	private array $defaults;
	/** @var array<string, RuleInterface>|null */
	private ?array $rules = null;

	/**
	 * @param RuleInterface[] $defaults
	 */
	public function __construct( array $defaults ) {
		$this->defaults = $defaults;
	}

	public static function create_default(): self {
		return new self( [ new NoUsersRule(), new InactiveRule(), new HighMediaRule() ] );
	}

	/**
	 * @return array<string, RuleInterface>
	 */
	public function all(): array {
		if ( null !== $this->rules ) {
			return $this->rules;
		}
		$rules = [];
		foreach ( (array) apply_filters( 'msradar_alert_rules', $this->defaults ) as $rule ) {
			if ( $rule instanceof RuleInterface ) {
				$rules[ $rule->id() ] = $rule;
			}
		}
		if ( did_action( 'plugins_loaded' ) ) {
			$this->rules = $rules;
		}
		return $rules;
	}

	public function get( string $id ): ?RuleInterface {
		return $this->all()[ $id ] ?? null;
	}
}
