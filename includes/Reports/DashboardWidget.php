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
		} catch ( \RuntimeException $error ) {
			do_action( 'msradar_error', __METHOD__, $error );
			echo '<p>' . esc_html__( 'Multisite Radar could not read its data. Try again in a moment.', 'multisite-radar' ) . '</p>';
			return;
		}

		$figures = [
			[ __( 'Sites', 'multisite-radar' ), (int) $summary['total_sites'] ],
			[ __( 'Sites with an error', 'multisite-radar' ), (int) $summary['by_severity']['error'] ],
			[ __( 'Sites with a warning', 'multisite-radar' ), (int) $summary['by_severity']['warning'] ],
			[ __( 'Unused plugins', 'multisite-radar' ), (int) $inventory['plugins']['unused'] ],
			[ __( 'Pending updates', 'multisite-radar' ), (int) $inventory['plugins']['updates'] + (int) $inventory['themes']['updates'] ],
		];
		echo '<ul class="msradar-widget__figures">';
		foreach ( $figures as $figure ) {
			printf( '<li><strong>%1$s</strong> %2$s</li>', esc_html( number_format_i18n( $figure[1] ) ), esc_html( $figure[0] ) );
		}
		echo '</ul>';

		if ( [] === $top['items'] ) {
			echo '<p>' . esc_html__( 'No alert on the network.', 'multisite-radar' ) . '</p>';
		} else {
			echo '<h3>' . esc_html__( 'Main alerts', 'multisite-radar' ) . '</h3><ul>';
			foreach ( $top['items'] as $alert ) {
				printf(
					'<li><a href="%1$s">%2$s</a> — %3$s</li>',
					esc_url( Menu::url( 'sites', [ 'site' => (int) $alert['site']['id'] ] ) ),
					esc_html( $alert['site']['name'] ),
					esc_html( $alert['message'] )
				);
			}
			echo '</ul>';
		}
		printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( Menu::url( 'overview' ) ), esc_html__( 'Open Multisite Radar', 'multisite-radar' ) );
	}
}
