<?php
namespace MultisiteRadar\Tests\Alerts;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\RuleRegistry;
use MultisiteRadar\Alerts\Rules\CronOverdueRule;
use MultisiteRadar\Alerts\Rules\HeavyAutoloadRule;
use MultisiteRadar\Alerts\Rules\HighMediaRule;
use MultisiteRadar\Alerts\Rules\InactiveRule;
use MultisiteRadar\Alerts\Rules\NoAdminRule;
use MultisiteRadar\Alerts\Rules\NoUsersRule;
use MultisiteRadar\Alerts\Rules\SearchHiddenRule;
use MultisiteRadar\Alerts\Severity;
use MultisiteRadar\Storage\SiteRecord;
use MultisiteRadar\Tests\TestCase;

final class RulesTest extends TestCase {

	public function test_no_users(): void {
		$rule = new NoUsersRule();

		$this->assertInstanceOf( Alert::class, $rule->evaluate( $this->build_record( [ 'users_count' => 0 ] ), [], time() ) );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'users_count' => 2 ] ), [], time() ) );
		$this->assertNull(
			$rule->evaluate( $this->build_record( [ 'users_count' => 0, 'scanned_at' => null ] ), [], time() ),
			'A site that was never scanned is never flagged.'
		);
	}

	public function test_inactive_uses_whole_months_of_thirty_days(): void {
		$now  = (int) strtotime( '2026-10-01 00:00:00 UTC' );
		$at   = static fn ( int $days ): string => gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );
		$rule = new InactiveRule();

		$alert = $rule->evaluate( $this->build_record( [ 'last_activity_gmt' => $at( 180 ) ] ), [ 'months' => 6 ], $now );
		$this->assertNotNull( $alert );
		$this->assertSame( [ 'months' => 6 ], $alert->args );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'last_activity_gmt' => $at( 179 ) ] ), [ 'months' => 6 ], $now ) );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'last_activity_gmt' => null ] ), [ 'months' => 6 ], $now ) );
		$this->assertSame( 'Inactive for 6 months', $rule->message( [ 'months' => 6 ] ) );
		$this->assertSame( 'Inactive for 1 month', $rule->message( [ 'months' => 1 ] ) );
	}

	public function test_high_media(): void {
		$rule = new HighMediaRule();

		$alert = $rule->evaluate( $this->build_record( [ 'media_count' => 1000 ] ), [ 'threshold' => 1000 ], time() );
		$this->assertSame( [ 'count' => 1000, 'threshold' => 1000 ], $alert->args );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'media_count' => 999 ] ), [ 'threshold' => 1000 ], time() ) );
		$this->assertSame( '1,000 media files (threshold: 1,000)', $rule->message( $alert->args ) );
	}

	public function test_every_default_rule_is_well_formed(): void {
		foreach ( RuleRegistry::create_default()->all() as $id => $rule ) {
			$this->assertSame( 1, preg_match( RuleRegistry::ID_PATTERN, $id ), $id );
			$this->assertNotSame( '', $rule->label(), $id );
			$this->assertNotSame( '', $rule->description(), $id );
			$this->assertTrue( Severity::is_valid( $rule->default_severity() ), $id );
			$this->assertSame( 'object', $rule->params_schema()['type'], $id );
			$this->assertFalse( $rule->params_schema()['additionalProperties'], $id );
			$this->assertTrue( rest_validate_value_from_schema( $rule->default_params(), $rule->params_schema(), 'params' ), $id );
		}
	}

	public function test_the_default_rules_follow_the_order_of_the_spec(): void {
		$this->assertSame(
			[ 'no_users', 'inactive', 'high_media', 'no_admin', 'heavy_autoload', 'search_hidden', 'cron_overdue' ],
			array_keys( RuleRegistry::create_default()->all() )
		);
	}

	public function test_no_admin_leaves_sites_without_accounts_to_no_users(): void {
		$rule = new NoAdminRule();

		$this->assertNotNull( $rule->evaluate( $this->build_record( [ 'users_count' => 3, 'admins_count' => 0 ] ), [], time() ) );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'users_count' => 3, 'admins_count' => 1 ] ), [], time() ) );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'users_count' => 0, 'admins_count' => 0 ] ), [], time() ), 'no_users already flags a site without accounts.' );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'users_count' => 3, 'scanned_at' => null ] ), [], time() ) );
		$this->assertSame( 'No account has the administrator role on this site.', $rule->message( [] ) );
	}

	public function test_heavy_autoload(): void {
		$rule = new HeavyAutoloadRule();

		$alert = $rule->evaluate( $this->build_record( [ 'autoload_bytes' => 800 * KB_IN_BYTES ] ), [ 'kilobytes' => 800 ], time() );
		$this->assertSame( [ 'kilobytes' => 800, 'threshold' => 800 ], $alert->args );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'autoload_bytes' => 800 * KB_IN_BYTES - 1 ] ), [ 'kilobytes' => 800 ], time() ) );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'autoload_bytes' => null ] ), [ 'kilobytes' => 800 ], time() ), 'Not measured yet: no alert.' );
		$this->assertSame( '1,024 KB of autoloaded options (threshold: 800 KB)', $rule->message( [ 'kilobytes' => 1024, 'threshold' => 800 ] ) );
	}

	public function test_search_hidden_ignores_the_sites_that_are_not_served(): void {
		$rule = new SearchHiddenRule();

		$this->assertNotNull( $rule->evaluate( $this->build_record( [ 'is_public' => false ] ), [], time() ) );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'is_public' => true ] ), [], time() ) );
		foreach ( [ 'is_archived', 'is_spam', 'is_deleted' ] as $flag ) {
			$this->assertNull( $rule->evaluate( $this->build_record( [ 'is_public' => false, $flag => true ] ), [], time() ), $flag );
		}
		$this->assertSame( 'Search engines are asked not to index this site.', $rule->message( [] ) );
	}

	public function test_cron_overdue_measures_the_delay_at_the_time_of_the_analysis(): void {
		$rule   = new CronOverdueRule();
		$later  = (int) strtotime( '2026-12-01 00:00:00 UTC' );
		$record = fn ( string $oldest, array $props = [] ): SiteRecord => $this->build_record(
			array_merge(
				[
					'scanned_at' => '2026-09-01 12:00:00',
					'data'       => [
						'cron' => [
							'overdue_count'      => 4,
							'oldest_overdue_gmt' => $oldest,
						],
					],
				],
				$props
			)
		);

		$alert = $rule->evaluate( $record( '2026-08-31 06:00:00' ), [ 'hours' => 24 ], $later );
		$this->assertSame( [ 'count' => 4, 'hours' => 30 ], $alert->args );
		$this->assertNull( $rule->evaluate( $record( '2026-08-31 13:00:00' ), [ 'hours' => 24 ], $later ), 'Three months later, the delay is still the one seen at the analysis.' );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'scanned_at' => '2026-09-01 12:00:00' ] ), [ 'hours' => 24 ], $later ), 'Analysed before 2.0.0-beta.4: no data, no alert.' );
		foreach ( [ 'is_archived', 'is_spam', 'is_deleted' ] as $flag ) {
			$this->assertNull( $rule->evaluate( $record( '2026-08-01 00:00:00', [ $flag => true ] ), [ 'hours' => 24 ], $later ), $flag );
		}
		$this->assertSame( 'At the last analysis, 4 scheduled tasks were overdue; the oldest had been waiting for 1 day.', $rule->message( [ 'count' => 4, 'hours' => 30 ] ) );
		$this->assertSame( 'At the last analysis, 1 scheduled task was overdue; the oldest had been waiting for 2 days.', $rule->message( [ 'count' => 1, 'hours' => 48 ] ) );
	}

	public function test_rules_with_an_invalid_identifier_are_ignored(): void {
		$bad    = new class() implements RuleInterface {
			public function id(): string {
				return 'bad,id';
			}
			public function label(): string {
				return 'Bad';
			}
			public function description(): string {
				return '';
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
				return null;
			}
			public function message( array $args ): string {
				return '';
			}
		};
		$add    = static function ( array $rules ) use ( $bad ): array {
			$rules[] = $bad;
			return $rules;
		};
		$this->setExpectedIncorrectUsage( RuleRegistry::class . '::all' );
		add_filter( 'msradar_alert_rules', $add );
		try {
			$ids = array_keys( ( new RuleRegistry( [ new HighMediaRule() ] ) )->all() );
		} finally {
			remove_filter( 'msradar_alert_rules', $add );
		}

		$this->assertSame( [ 'high_media' ], $ids );
	}

	public function test_the_media_message_is_pluralised_and_localised(): void {
		$rule = new HighMediaRule();

		$this->assertSame( '1 media file (threshold: 1)', $rule->message( [ 'count' => 1, 'threshold' => 1 ] ) );
		$this->assertSame( '1,500 media files (threshold: 1,000)', $rule->message( [ 'count' => 1500, 'threshold' => 1000 ] ) );
	}
}
