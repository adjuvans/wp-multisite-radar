<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Alerts\AlertEvaluator;
use MultisiteRadar\Alerts\AlertFormatter;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Storage\SitesRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Alertes du réseau : synthèse (sites par gravité et par règle) et liste site × règle.
 */
final class AlertsQuery {

	public const ORDERBY = [ 'rule', 'name', 'severity' ];

	private SitesRepository $sites;
	private RuleRegistry $rules;
	private AlertEvaluator $evaluator;
	private AlertFormatter $formatter;

	public function __construct( SitesRepository $sites, RuleRegistry $rules, AlertEvaluator $evaluator, AlertFormatter $formatter ) {
		$this->sites     = $sites;
		$this->rules     = $rules;
		$this->evaluator = $evaluator;
		$this->formatter = $formatter;
	}

	public static function defaults(): array {
		return [
			'page'     => 1,
			'per_page' => 20,
			'search'   => '',
			'severity' => [],
			'rule'     => [],
			'orderby'  => 'rule',
			'order'    => 'asc',
		];
	}

	/**
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function summary( int $network_id ): array {
		$rules   = $this->rules->all();
		$counts  = $this->sites->alert_counts( $network_id, array_keys( $rules ) );
		$by_rule = [];
		foreach ( $rules as $id => $rule ) {
			$config    = $this->evaluator->config( $rule );
			$by_rule[] = [
				'rule'     => $id,
				'label'    => $rule->label(),
				'severity' => $config['severity'],
				'enabled'  => $config['enabled'],
				'count'    => $counts['rules'][ $id ] ?? 0,
			];
		}

		return [
			'total_sites'       => $counts['total'],
			'scanned_sites'     => $counts['total'] - $counts['pending'],
			'pending_sites'     => $counts['pending'],
			'sites_with_alerts' => $counts['with_alerts'],
			'by_severity'       => [
				'error'   => $counts['error'],
				'warning' => $counts['warning'],
				'info'    => $counts['info'],
			],
			'by_rule'           => $by_rule,
		];
	}

	/**
	 * Liste site × règle du réseau courant. La gravité est celle des réglages actuels de la règle :
	 * elle sert au filtre, au tri et à l'affichage. Les règles désactivées n'apparaissent pas.
	 *
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$args       = array_merge( self::defaults(), $args );
		$severities = array_values( array_intersect( array_map( 'strval', (array) $args['severity'] ), [ Severity::ERROR, Severity::WARNING, Severity::INFO ] ) );
		$wanted     = array_map( 'strval', (array) $args['rule'] );
		$levels     = [];
		$configs    = [];
		foreach ( $this->rules->all() as $id => $rule ) {
			$config = $this->evaluator->config( $rule );
			if ( ! $config['enabled'] ) {
				continue;
			}
			if ( [] !== $wanted && ! in_array( $id, $wanted, true ) ) {
				continue;
			}
			if ( [] !== $severities && ! in_array( $config['severity'], $severities, true ) ) {
				continue;
			}
			$levels[ $id ]  = Severity::level( $config['severity'] );
			$configs[ $id ] = $config;
		}

		$orderby = (string) $args['orderby'];
		$result  = $this->sites->alert_pairs(
			[
				'network_id' => get_current_network_id(),
				'rules'      => $levels,
				'search'     => trim( (string) $args['search'] ),
				'orderby'    => in_array( $orderby, self::ORDERBY, true ) ? $orderby : 'rule',
				'order'      => 'desc' === strtolower( (string) $args['order'] ) ? 'desc' : 'asc',
				'page'       => min( SitesQuery::MAX_PAGE, max( 1, (int) $args['page'] ) ),
				'per_page'   => min( 100, max( 1, (int) $args['per_page'] ) ),
			]
		);

		$records = $this->sites->find_many( array_values( array_unique( array_column( $result['items'], 'site_id' ) ) ) );
		$items   = [];
		foreach ( $result['items'] as $pair ) {
			$record = $records[ $pair['site_id'] ] ?? null;
			$rule   = $this->rules->get( $pair['rule'] );
			if ( null === $record || null === $rule ) {
				continue; // Site supprimé entre les deux lectures.
			}
			$items[] = [
				'id'       => $pair['site_id'] . ':' . $pair['rule'],
				'site'     => SitesQuery::identity( $record ),
				'rule'     => $pair['rule'],
				'label'    => $rule->label(),
				'severity' => $configs[ $pair['rule'] ]['severity'],
				'message'  => $this->message( $record, $rule ),
			];
		}

		return [
			'items' => $items,
			'total' => $result['total'],
		];
	}

	/**
	 * Message traduit de l'alerte stockée, ou le libellé de la règle si les arguments manquent.
	 */
	private function message( SiteRecord $record, RuleInterface $rule ): string {
		foreach ( (array) ( $record->data['alerts'] ?? [] ) as $stored ) {
			if ( is_array( $stored ) && $rule->id() === ( $stored['rule'] ?? null ) ) {
				$formatted = $this->formatter->format( [ $stored ] );
				return [] !== $formatted ? $formatted[0]['message'] : $rule->label();
			}
		}
		return $rule->label();
	}
}
