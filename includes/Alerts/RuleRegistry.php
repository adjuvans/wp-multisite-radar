<?php
namespace MultisiteRadar\Alerts;

use MultisiteRadar\Alerts\Rules\CronOverdueRule;
use MultisiteRadar\Alerts\Rules\DiskQuotaRule;
use MultisiteRadar\Alerts\Rules\HeavyAutoloadRule;
use MultisiteRadar\Alerts\Rules\HighMediaRule;
use MultisiteRadar\Alerts\Rules\InactiveRule;
use MultisiteRadar\Alerts\Rules\InsecureUrlRule;
use MultisiteRadar\Alerts\Rules\MissingThemeRule;
use MultisiteRadar\Alerts\Rules\NoAdminRule;
use MultisiteRadar\Alerts\Rules\NoUsersRule;
use MultisiteRadar\Alerts\Rules\SearchHiddenRule;
use MultisiteRadar\Alerts\Rules\UpdatesPendingRule;

defined( 'ABSPATH' ) || exit;

/**
 * Règles disponibles. Le filtre msradar_alert_rules est appliqué à la première lecture,
 * et pas avant plugins_loaded, pour que les plugins tiers aient pu s'y abonner.
 */
final class RuleRegistry {

	public const ID_PATTERN = '/^[a-z0-9_]{1,40}\z/';

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

	/**
	 * @param NetworkState|null $state État du réseau partagé par les règles qui en dépendent (celui du plugin en
	 *                                 production, un état neuf sinon).
	 */
	public static function create_default( ?NetworkState $state = null ): self {
		$state = $state ?? new NetworkState();
		return new self(
			[
				new NoUsersRule(),
				new InactiveRule(),
				new HighMediaRule(),
				new NoAdminRule(),
				new MissingThemeRule( $state ),
				new UpdatesPendingRule( $state ),
				new InsecureUrlRule( $state ),
				new DiskQuotaRule( $state ),
				new HeavyAutoloadRule(),
				new SearchHiddenRule(),
				new CronOverdueRule(),
			]
		);
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
			if ( ! $rule instanceof RuleInterface ) {
				continue;
			}
			$id = $rule->id();
			if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
				// Une virgule ou un « % » casserait l'encodage « ,id, » de la colonne alert_rules.
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: %s: alert rule identifier. */
							__( 'The alert rule "%s" was ignored: identifiers may only contain lowercase letters, digits and underscores (40 characters at most).', 'multisite-radar' ),
							$id
						)
					),
					'2.0.0'
				);
				continue;
			}
			$rules[ $id ] = $rule;
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
