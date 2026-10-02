<?php
namespace MultisiteRadar\Tests\Alerts;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\AlertEvaluator;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Settings\Settings;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Tests\TestCase;

final class AlertEvaluatorTest extends TestCase {

	private function fresh_evaluator(): AlertEvaluator {
		return new AlertEvaluator( $this->plugin()->rules(), $this->plugin()->settings() );
	}

	public function test_apply_sets_level_count_rule_ids_and_data(): void {
		$record = $this->build_record( [ 'users_count' => 0, 'media_count' => 5000 ] );

		$this->fresh_evaluator()->apply( $record, time() );

		$this->assertSame( 3, $record->alert_level );
		$this->assertSame( 2, $record->alerts_count );
		$this->assertSame( ',no_users,high_media,', $record->alert_rules );
		$this->assertSame( [ 'rule' => 'no_users', 'severity' => 'error', 'args' => [] ], $record->data['alerts'][0] );
	}

	public function test_no_alert_clears_previous_values(): void {
		$record = $this->build_record( [ 'users_count' => 4, 'admins_count' => 1, 'alert_level' => 3, 'alerts_count' => 1, 'alert_rules' => ',no_users,' ] );

		$this->fresh_evaluator()->apply( $record, time() );

		$this->assertSame( 0, $record->alert_level );
		$this->assertSame( '', $record->alert_rules );
		$this->assertSame( [], $record->data['alerts'] );
	}

	public function test_settings_disable_rules_and_override_severity_and_params(): void {
		$this->plugin()->settings()->update(
			[
				'alerts' => [
					'rules' => [
						'no_users'   => [ 'enabled' => false ],
						'high_media' => [
							'severity' => 'warning',
							'params'   => [ 'threshold' => 10 ],
						],
					],
				],
			]
		);
		$record = $this->build_record( [ 'users_count' => 0, 'media_count' => 50 ] );

		$this->fresh_evaluator()->apply( $record, time() );

		$this->assertSame( 2, $record->alert_level );
		$this->assertSame( ',high_media,', $record->alert_rules );
		$this->assertSame( [ 'count' => 50, 'threshold' => 10 ], $record->data['alerts'][0]['args'] );
	}

	public function test_invalid_stored_params_fall_back_to_defaults(): void {
		update_site_option( Settings::OPTION, [ 'alerts' => [ 'rules' => [ 'high_media' => [ 'params' => [ 'threshold' => 'lots' ] ] ] ] ] );
		$this->plugin()->settings()->reset_cache();

		$config = $this->fresh_evaluator()->config( $this->plugin()->rules()->get( 'high_media' ) );

		$this->assertSame( [ 'threshold' => 1000 ], $config['params'] );
		$this->assertSame( 'info', $config['severity'] );
		$this->assertTrue( $config['enabled'] );
	}

	public function test_third_party_rules_can_be_added_and_invalid_entries_are_ignored(): void {
		add_filter(
			'msradar_alert_rules',
			static function ( array $rules ): array {
				$rules[] = new class() implements RuleInterface {
					public function id(): string {
						return 'always';
					}
					public function label(): string {
						return 'Always';
					}
					public function description(): string {
						return 'Always raised.';
					}
					public function default_severity(): string {
						return 'info';
					}
					public function params_schema(): array {
						return [ 'type' => 'object' ];
					}
					public function default_params(): array {
						return [];
					}
					public function evaluate( SiteRecord $site, array $params, int $now ): ?Alert {
						return new Alert( 'always', 'info' );
					}
					public function message( array $args ): string {
						return 'Always';
					}
				};
				$rules[] = 'not a rule';
				return $rules;
			}
		);

		$registry = RuleRegistry::create_default();

		$this->assertSame( [ 'no_users', 'inactive', 'high_media', 'no_admin', 'missing_theme', 'updates_pending', 'insecure_url', 'disk_quota', 'heavy_autoload', 'search_hidden', 'cron_overdue', 'always' ], array_keys( $registry->all() ) );
	}

	public function test_formatter_builds_labels_and_messages(): void {
		$formatted = $this->plugin()->formatter()->format(
			[
				[ 'rule' => 'inactive', 'severity' => 'warning', 'args' => [ 'months' => 8 ] ],
				[ 'rule' => 'gone', 'severity' => 'info', 'args' => [] ],
				[ 'rule' => 'inactive', 'severity' => 'bogus' ],
				'garbage',
			]
		);

		$this->assertSame(
			[
				[ 'rule' => 'inactive', 'severity' => 'warning', 'label' => 'Inactive site', 'message' => 'Inactive for 8 months' ],
				[ 'rule' => 'gone', 'severity' => 'info', 'label' => 'gone', 'message' => 'gone' ],
			],
			$formatted
		);
	}

	public function test_rules_read_the_network_state_again_after_a_reset(): void {
		set_site_transient( 'update_plugins', (object) [ 'response' => [ 'akismet/akismet.php' => (object) [ 'new_version' => '9.0' ] ] ] );
		$this->plugin()->reset_caches();
		$record = $this->build_record(
			[
				'users_count'  => 1,
				'admins_count' => 1,
				'data'         => [ 'plugins_local' => [ 'akismet/akismet.php' ] ],
			]
		);

		$this->plugin()->evaluator()->apply( $record, time() );
		$this->assertSame( ',updates_pending,', $record->alert_rules );

		delete_site_transient( 'update_plugins' );
		$this->plugin()->reset_caches();
		$this->plugin()->evaluator()->apply( $record, time() );
		$this->assertSame( '', $record->alert_rules );
	}
}
