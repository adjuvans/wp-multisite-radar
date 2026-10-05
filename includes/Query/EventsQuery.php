<?php
namespace MultisiteRadar\Query;

use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Storage\EventsRepository;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Storage\SitesRepository;
use MultisiteRadar\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Le journal pour l'extérieur (REST, ability, récapitulatif) : site, nom du sujet et message, construits à la lecture
 * dans la langue du lecteur. Un site supprimé garde le nom et l'adresse notés dans l'événement.
 */
final class EventsQuery {

	private EventsRepository $events;
	private SitesRepository $sites;
	private RuleRegistry $rules;

	/**
	 * @var array<string, array{name: string, version: string}>|null Plugins installés, lus une fois.
	 */
	private ?array $plugins = null;

	public function __construct( EventsRepository $events, SitesRepository $sites, RuleRegistry $rules ) {
		$this->events = $events;
		$this->sites  = $sites;
		$this->rules  = $rules;
	}

	public static function defaults(): array {
		return [
			'page'     => 1,
			'per_page' => 20,
			'since'    => null,
			'type'     => [],
			'site'     => 0,
		];
	}

	/**
	 * Événements du réseau courant, les plus récents d'abord.
	 *
	 * @param array $args page, per_page (≤ 100), since (date GMT « Y-m-d H:i:s » ou null), type (string[]), site (int, 0 : tous).
	 * @return array{items: array[], total: int}
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function list( array $args ): array {
		$args    = array_merge( self::defaults(), $args );
		$result  = $this->events->query(
			[
				'network_id' => get_current_network_id(),
				'since'      => null === $args['since'] ? null : (string) $args['since'],
				'types'      => (array) $args['type'],
				'site_id'    => max( 0, (int) $args['site'] ),
				'page'       => min( SitesQuery::MAX_PAGE, max( 1, (int) $args['page'] ) ),
				'per_page'   => min( 100, max( 1, (int) $args['per_page'] ) ),
			]
		);
		$records = $this->sites->find_many( array_values( array_unique( array_filter( array_column( $result['items'], 'site_id' ) ) ) ) );
		return [
			'items' => array_map(
				function ( array $event ) use ( $records ): array {
					return $this->format( $event, $records[ $event['site_id'] ] ?? null );
				},
				$result['items']
			),
			'total' => $result['total'],
		];
	}

	/**
	 * @return array<string, int> Type => nombre d'événements du réseau courant depuis $since (GMT).
	 * @throws \RuntimeException Si la lecture échoue.
	 */
	public function counts( string $since ): array {
		return $this->events->counts( get_current_network_id(), $since );
	}

	/**
	 * @param array $event Élément de EventsRepository::query().
	 * @return array{id: int, type: string, site: array|null, subject: string, label: string, message: string, created_gmt: string}
	 */
	public function format( array $event, ?SiteRecord $record ): array {
		$label = $this->label( $event );
		return [
			'id'          => (int) $event['id'],
			'type'        => (string) $event['type'],
			'site'        => $this->site( $event, $record ),
			'subject'     => (string) $event['subject'],
			'label'       => $label,
			'message'     => $this->message( $event, $label ),
			'created_gmt' => mysql_to_rfc3339( (string) $event['created_at'] ),
		];
	}

	/**
	 * Identité actuelle du site, celle notée dans l'événement s'il n'existe plus, null pour le réseau entier.
	 */
	private function site( array $event, ?SiteRecord $record ): ?array {
		$site_id = (int) $event['site_id'];
		if ( 0 === $site_id ) {
			return null;
		}
		if ( null !== $record ) {
			return SitesQuery::identity( $record );
		}
		$name   = PlainText::from_html( (string) ( $event['meta']['name'] ?? '' ) );
		$is_url = in_array( $event['type'], [ 'site_created', 'site_deleted' ], true ) && '' !== $event['subject'];
		return [
			'id'        => $site_id,
			/* translators: %d: site ID. */
			'name'      => '' !== trim( $name ) ? $name : sprintf( __( 'Site #%d', 'multisite-radar' ), $site_id ),
			'url'       => $is_url ? set_url_scheme( 'http://' . $event['subject'] ) : '',
			'admin_url' => '',
		];
	}

	/**
	 * Nom lisible du sujet : nom du plugin, du thème, libellé de la règle, nom du site.
	 */
	private function label( array $event ): string {
		$subject = (string) $event['subject'];
		switch ( $event['type'] ) {
			case 'plugin_activated':
			case 'plugin_deactivated':
				$this->plugins ??= PluginsQuery::installed();
				$name            = $this->plugins[ $subject ]['name'] ?? '';
				return '' !== $name ? $name : $subject;
			case 'theme_switched':
				return self::theme_name( $subject );
			case 'alert_raised':
			case 'alert_resolved':
				$rule = $this->rules->get( $subject );
				return null !== $rule ? $rule->label() : $subject;
			default:
				$name = PlainText::from_html( (string) ( $event['meta']['name'] ?? '' ) );
				return '' !== trim( $name ) ? $name : $subject;
		}
	}

	private function message( array $event, string $label ): string {
		$network = ! empty( $event['meta']['network'] );
		switch ( $event['type'] ) {
			case 'site_created':
				return __( 'Site created.', 'multisite-radar' );
			case 'site_deleted':
				return __( 'Site deleted.', 'multisite-radar' );
			case 'plugin_activated':
				if ( $network ) {
					/* translators: %s: plugin name. */
					return sprintf( __( 'Plugin %s network activated.', 'multisite-radar' ), $label );
				}
				/* translators: %s: plugin name. */
				return sprintf( __( 'Plugin %s activated.', 'multisite-radar' ), $label );
			case 'plugin_deactivated':
				if ( $network ) {
					/* translators: %s: plugin name. */
					return sprintf( __( 'Plugin %s network deactivated.', 'multisite-radar' ), $label );
				}
				/* translators: %s: plugin name. */
				return sprintf( __( 'Plugin %s deactivated.', 'multisite-radar' ), $label );
			case 'theme_switched':
				$from = self::theme_name( (string) ( $event['meta']['from'] ?? '' ) );
				if ( '' === $from ) {
					/* translators: %s: theme name. */
					return sprintf( __( 'Theme switched to %s.', 'multisite-radar' ), $label );
				}
				/* translators: 1: previous theme name, 2: new theme name. */
				return sprintf( __( 'Theme switched from %1$s to %2$s.', 'multisite-radar' ), $from, $label );
			case 'alert_raised':
				/* translators: %s: alert rule label. */
				return sprintf( __( 'New alert: %s.', 'multisite-radar' ), $label );
			case 'alert_resolved':
				/* translators: %s: alert rule label. */
				return sprintf( __( 'Alert resolved: %s.', 'multisite-radar' ), $label );
		}
		return $label;
	}

	private static function theme_name( string $stylesheet ): string {
		if ( '' === $stylesheet ) {
			return '';
		}
		$theme = wp_get_theme( $stylesheet );
		return $theme->exists() ? PlainText::from_html( wp_strip_all_tags( (string) $theme->get( 'Name' ) ) ) : $stylesheet;
	}
}
