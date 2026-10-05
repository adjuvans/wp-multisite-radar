<?php
namespace MultisiteRadar\Reports;

defined( 'ABSPATH' ) || exit;

use MultisiteRadar\Admin\Menu;
use MultisiteRadar\Capabilities;
use MultisiteRadar\Query\AlertsQuery;
use MultisiteRadar\Query\InventoryQuery;

/**
 * Widget du tableau de bord réseau (spec §7.3), rendu en PHP : chiffres clés et cinq alertes principales.
 */
final class DashboardWidget {

	public const ID = 'msradar_summary';

	private const TOP = 5;

	public const STYLE_HANDLE = 'msradar-dashboard-widget';

	/**
	 * Style du widget (écart E4 du plan rc.2) : tuiles en grille, pastilles de gravité aux couleurs de l'écran Alertes.
	 */
	private const STYLE = '.msradar-widget__tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;margin:0 0 16px;padding:0;list-style:none}'
		. '.msradar-widget__tile{margin:0}'
		. '.msradar-widget__tile a{display:block;height:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #dcdcde;border-radius:4px;color:#1d2327;text-decoration:none}'
		. '.msradar-widget__tile a:hover,.msradar-widget__tile a:focus{border-color:var(--wp-admin-theme-color,#2271b1)}'
		. '.msradar-widget__value{display:block;font-size:20px;font-weight:600;line-height:1.3}'
		. '.msradar-widget__label{display:block;color:#50575e;font-size:12px}'
		. '.msradar-widget__alerts{margin:0;padding:0;list-style:none}'
		. '.msradar-widget__alerts li{margin:0 0 8px}'
		. '.msradar-widget__severity{display:inline-block;margin-inline-end:6px;padding:0 6px;border-radius:2px;font-size:12px}'
		. '.msradar-widget__severity--error{background:#fcf0f1;color:#8a2424}'
		. '.msradar-widget__severity--warning{background:#fcf9e8;color:#6d4c00}'
		. '.msradar-widget__severity--info{background:#f0f6fc;color:#0a4b78}'
		. '.msradar-widget__more{margin:12px 0 0;text-align:end}';

	private AlertsQuery $alerts;
	private InventoryQuery $inventory;

	public function __construct( AlertsQuery $alerts, InventoryQuery $inventory ) {
		$this->alerts    = $alerts;
		$this->inventory = $inventory;
	}

	public function register(): void {
		add_action( 'wp_network_dashboard_setup', [ $this, 'add' ] );
	}

	public function add(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}
		wp_register_style( self::STYLE_HANDLE, false, [], MSRADAR_VERSION );
		wp_add_inline_style( self::STYLE_HANDLE, self::STYLE );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_dashboard_widget( self::ID, __( 'Multisite Radar', 'multisite-radar' ), [ $this, 'render' ] );
	}

	public function render(): void {
		try {
			$summary   = $this->alerts->summary( get_current_network_id() );
			$inventory = $this->inventory->summary();
			$top       = $this->alerts->list(
				[
					'orderby'  => 'severity',
					'order'    => 'desc',
					'per_page' => self::TOP,
				]
			);
		} catch ( \Throwable $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
			echo '<p>' . esc_html__( 'Multisite Radar could not read its data. Try again in a moment.', 'multisite-radar' ) . '</p>';
			return;
		}

		$plugin_updates = (int) $inventory['plugins']['updates'];
		$theme_updates  = (int) $inventory['themes']['updates'];
		$tiles          = [
			[ __( 'Sites', 'multisite-radar' ), (int) $summary['total_sites'], Menu::url( 'sites' ) ],
			[ __( 'Sites with an error', 'multisite-radar' ), (int) $summary['by_severity']['error'], Menu::url( 'sites', [ 'alert_level' => 'error' ] ) ],
			[ __( 'Sites with a warning', 'multisite-radar' ), (int) $summary['by_severity']['warning'], Menu::url( 'sites', [ 'alert_level' => 'warning' ] ) ],
			[ __( 'Unused plugins', 'multisite-radar' ), (int) $inventory['plugins']['unused'], Menu::url( 'plugins', [ 'status' => 'unused' ] ) ],
			[ __( 'Pending updates', 'multisite-radar' ), $plugin_updates + $theme_updates, Menu::url( 0 === $plugin_updates && $theme_updates > 0 ? 'themes' : 'plugins', [ 'has_update' => '1' ] ) ],
		];
		echo '<ul class="msradar-widget__tiles">';
		foreach ( $tiles as $tile ) {
			printf(
				'<li class="msradar-widget__tile"><a href="%1$s"><span class="msradar-widget__value">%2$s</span> <span class="msradar-widget__label">%3$s</span></a></li>',
				esc_url( $tile[2] ),
				esc_html( number_format_i18n( $tile[1] ) ),
				esc_html( $tile[0] )
			);
		}
		echo '</ul>';

		if ( [] === $top['items'] ) {
			echo '<p>' . esc_html__( 'No alert on the network.', 'multisite-radar' ) . '</p>';
		} else {
			$severities = [
				'error'   => __( 'Error', 'multisite-radar' ),
				'warning' => __( 'Warning', 'multisite-radar' ),
				'info'    => __( 'Info', 'multisite-radar' ),
			];
			echo '<h3>' . esc_html__( 'Main alerts', 'multisite-radar' ) . '</h3><ul class="msradar-widget__alerts">';
			foreach ( $top['items'] as $alert ) {
				$severity = isset( $severities[ $alert['severity'] ] ) ? (string) $alert['severity'] : 'info';
				// Lien souligné : au milieu d'un texte, la couleur seule ne le distingue pas (WCAG 1.4.1).
				printf(
					'<li><span class="msradar-widget__severity msradar-widget__severity--%1$s">%2$s</span> <a href="%3$s" style="text-decoration: underline;">%4$s</a> — %5$s</li>',
					esc_attr( $severity ),
					esc_html( $severities[ $severity ] ),
					esc_url( Menu::url( 'sites', [ 'site' => (int) $alert['site']['id'] ] ) ),
					esc_html( $alert['site']['name'] ),
					esc_html( $alert['message'] )
				);
			}
			echo '</ul>';
		}
		printf( '<p class="msradar-widget__more"><a href="%1$s">%2$s</a></p>', esc_url( Menu::url( 'overview' ) ), esc_html__( 'Open Multisite Radar', 'multisite-radar' ) );
	}
}
