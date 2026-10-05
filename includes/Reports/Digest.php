<?php
namespace MultisiteRadar\Reports;

use MultisiteRadar\Admin\Menu;
use MultisiteRadar\Query\AlertsQuery;
use MultisiteRadar\Query\EventsQuery;
use MultisiteRadar\Scan\Queue;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Récapitulatif hebdomadaire par e-mail (spec §7.3, écart E7) : alertes nouvelles et résolues des 7 derniers jours,
 * résumé des changements, lien vers la Vue d'ensemble. HTML avec une alternative texte ; un e-mail par destinataire.
 */
final class Digest {

	public const SENT_OPTION = 'msradar_digest_sent';

	private const DAYS       = 7;
	private const MAX_ALERTS = 20;

	private Settings $settings;
	private EventsQuery $events;
	private AlertsQuery $alerts;

	public function __construct( Settings $settings, EventsQuery $events, AlertsQuery $alerts ) {
		$this->settings = $settings;
		$this->events   = $events;
		$this->alerts   = $alerts;
	}

	public function register(): void {
		// Après History::daily() (priorité 20), qui a purgé les événements trop anciens.
		add_action( Queue::HOOK_DAILY, [ $this, 'maybe_send' ], 30 );
	}

	/**
	 * Sur la tâche quotidienne : le jour réglé (fuseau du site principal), une seule fois ce jour-là. Non typé :
	 * WordPress appelle les hooks avec un argument vide.
	 *
	 * @param mixed $now Horodatage Unix (tests) ; maintenant sinon.
	 */
	public function maybe_send( $now = null ): void {
		$now = is_int( $now ) ? $now : time();
		if ( ! (bool) $this->settings->get( 'reports.digest_enabled', false ) ) {
			return;
		}
		if ( (int) wp_date( 'w', $now ) !== (int) $this->settings->get( 'reports.digest_day', 1 ) ) {
			return;
		}
		$today = (string) wp_date( 'Y-m-d', $now );
		if ( get_site_option( self::SENT_OPTION ) === $today ) {
			return;
		}
		$recipients = $this->recipients();
		if ( [] === $recipients ) {
			return;
		}
		try {
			$sent = $this->send( $recipients, $now );
		} catch ( \Throwable $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
			return;
		}
		if ( $sent ) {
			update_site_option( self::SENT_OPTION, $today );
		}
	}

	/**
	 * @return string[] Adresses valides, sans doublon : celles des super-admins, ou la liste saisie.
	 */
	public function recipients(): array {
		$emails = [];
		if ( 'custom' === $this->settings->get( 'reports.digest_recipients.mode', 'super_admins' ) ) {
			$emails = (array) $this->settings->get( 'reports.digest_recipients.emails', [] );
		} else {
			foreach ( get_super_admins() as $login ) {
				$user = get_user_by( 'login', $login );
				if ( false !== $user ) {
					$emails[] = $user->user_email;
				}
			}
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $emails ), 'is_email' ) ) );
	}

	/**
	 * Un e-mail par destinataire : aucun ne voit les adresses des autres.
	 *
	 * @param string[] $recipients Adresses.
	 * @return bool Vrai si au moins un e-mail est parti.
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function send( array $recipients, ?int $now = null ): bool {
		$mail = $this->compose( $now ?? time() );
		$alt  = static function ( $phpmailer ) use ( $mail ): void {
			$phpmailer->AltBody = $mail['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		};
		$sent = false;
		add_action( 'phpmailer_init', $alt );
		try {
			foreach ( $recipients as $recipient ) {
				$sent = wp_mail( $recipient, $mail['subject'], $mail['html'], [ 'Content-Type: text/html; charset=UTF-8' ] ) || $sent;
			}
		} finally {
			remove_action( 'phpmailer_init', $alt );
		}
		return $sent;
	}

	/**
	 * @return array{subject: string, html: string, text: string}
	 * @throws \RuntimeException Si une lecture échoue.
	 */
	public function compose( int $now ): array {
		$since    = gmdate( 'Y-m-d H:i:s', $now - self::DAYS * DAY_IN_SECONDS );
		$summary  = $this->alerts->summary( get_current_network_id() );
		$raised   = $this->events->list(
			[
				'since'    => $since,
				'type'     => [ 'alert_raised' ],
				'per_page' => self::MAX_ALERTS,
			]
		);
		$resolved = $this->events->list(
			[
				'since'    => $since,
				'type'     => [ 'alert_resolved' ],
				'per_page' => self::MAX_ALERTS,
			]
		);
		$network  = get_network();
		$name     = PlainText::from_html( null !== $network ? (string) $network->site_name : '' );

		/* translators: %s: network name. */
		$title    = sprintf( __( 'Multisite Radar summary for %s', 'multisite-radar' ), $name );
		$sections = [
			[
				'title' => __( 'Network', 'multisite-radar' ),
				'lines' => [
					self::figure( __( 'Sites', 'multisite-radar' ), (int) $summary['total_sites'] ),
					self::figure( __( 'Sites with an error', 'multisite-radar' ), (int) $summary['by_severity']['error'] ),
					self::figure( __( 'Sites with a warning', 'multisite-radar' ), (int) $summary['by_severity']['warning'] ),
				],
			],
			self::alerts_section(
				/* translators: %s: number of new alerts. */
				_n( '%s new alert', '%s new alerts', $raised['total'], 'multisite-radar' ),
				$raised
			),
			self::alerts_section(
				/* translators: %s: number of resolved alerts. */
				_n( '%s resolved alert', '%s resolved alerts', $resolved['total'], 'multisite-radar' ),
				$resolved
			),
			[
				'title' => __( 'Other changes', 'multisite-radar' ),
				'lines' => self::change_lines( $this->events->counts( $since ) ),
			],
		];

		return [
			/* translators: %s: network name. */
			'subject' => sprintf( __( '[%s] Weekly summary from Multisite Radar', 'multisite-radar' ), $name ),
			'html'    => self::html( $title, $sections, Menu::url( 'overview' ) ),
			'text'    => self::text( $title, $sections, Menu::url( 'overview' ) ),
		];
	}

	private static function figure( string $label, int $value ): string {
		/* translators: 1: label, 2: number. */
		return sprintf( __( '%1$s: %2$s', 'multisite-radar' ), $label, number_format_i18n( $value ) );
	}

	/**
	 * @param string                            $heading Titre au singulier ou au pluriel, avec %s pour le nombre.
	 * @param array{items: array[], total: int} $result  Résultat de EventsQuery::list().
	 * @return array{title: string, lines: string[]}
	 */
	private static function alerts_section( string $heading, array $result ): array {
		$lines = [];
		foreach ( $result['items'] as $item ) {
			$site    = null !== $item['site'] ? $item['site']['name'] : __( 'Network', 'multisite-radar' );
			$lines[] = $site . ' — ' . $item['label'];
		}
		$more = $result['total'] - count( $result['items'] );
		if ( $more > 0 ) {
			/* translators: %s: number of further alerts. */
			$lines[] = sprintf( _n( 'and %s more', 'and %s more', $more, 'multisite-radar' ), number_format_i18n( $more ) );
		}
		return [
			'title' => sprintf( $heading, number_format_i18n( $result['total'] ) ),
			'lines' => $lines,
		];
	}

	/**
	 * @param array<string, int> $counts Type => nombre.
	 * @return string[]
	 */
	private static function change_lines( array $counts ): array {
		$lines  = [];
		$labels = [
			/* translators: %s: number of sites. */
			'site_created'       => _n_noop( '%s site created', '%s sites created', 'multisite-radar' ),
			/* translators: %s: number of sites. */
			'site_deleted'       => _n_noop( '%s site deleted', '%s sites deleted', 'multisite-radar' ),
			/* translators: %s: number of plugins. */
			'plugin_activated'   => _n_noop( '%s plugin activated', '%s plugins activated', 'multisite-radar' ),
			/* translators: %s: number of plugins. */
			'plugin_deactivated' => _n_noop( '%s plugin deactivated', '%s plugins deactivated', 'multisite-radar' ),
			/* translators: %s: number of themes. */
			'theme_switched'     => _n_noop( '%s theme switched', '%s themes switched', 'multisite-radar' ),
		];
		foreach ( $labels as $type => $noop ) {
			$count = (int) ( $counts[ $type ] ?? 0 );
			if ( $count > 0 ) {
				$lines[] = sprintf( translate_nooped_plural( $noop, $count, 'multisite-radar' ), number_format_i18n( $count ) );
			}
		}
		return [] !== $lines ? $lines : [ __( 'No other change.', 'multisite-radar' ) ];
	}

	/**
	 * @param array<int, array{title: string, lines: string[]}> $sections
	 */
	private static function html( string $title, array $sections, string $url ): string {
		$html  = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; color: #1e1e1e; max-width: 640px;">';
		$html .= '<h1 style="font-size: 20px;">' . esc_html( $title ) . '</h1>';
		$html .= '<p>' . esc_html(
			/* translators: %d: number of days. */
			sprintf( _n( 'Changes of the last %d day.', 'Changes of the last %d days.', self::DAYS, 'multisite-radar' ), self::DAYS )
		) . '</p>';
		foreach ( $sections as $section ) {
			$html .= '<h2 style="font-size: 16px; margin-top: 24px;">' . esc_html( $section['title'] ) . '</h2>';
			if ( [] !== $section['lines'] ) {
				$html .= '<ul>';
				foreach ( $section['lines'] as $line ) {
					$html .= '<li>' . esc_html( $line ) . '</li>';
				}
				$html .= '</ul>';
			}
		}
		$html .= '<p style="margin-top: 24px;"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open Multisite Radar', 'multisite-radar' ) . '</a></p>';
		return $html . '</div>';
	}

	/**
	 * @param array<int, array{title: string, lines: string[]}> $sections
	 */
	private static function text( string $title, array $sections, string $url ): string {
		/* translators: %d: number of days. */
		$text = $title . "\n\n" . sprintf( _n( 'Changes of the last %d day.', 'Changes of the last %d days.', self::DAYS, 'multisite-radar' ), self::DAYS ) . "\n";
		foreach ( $sections as $section ) {
			$text .= "\n" . $section['title'] . "\n";
			foreach ( $section['lines'] as $line ) {
				$text .= '- ' . $line . "\n";
			}
		}
		/* translators: %s: address of the Multisite Radar overview page. */
		return $text . "\n" . sprintf( __( 'Open Multisite Radar: %s', 'multisite-radar' ), $url ) . "\n";
	}
}
