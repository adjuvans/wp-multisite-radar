<?php
namespace MultisiteRadar\Tests\Alerts;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\NetworkState;
use MultisiteRadar\Alerts\RuleInterface;
use MultisiteRadar\Alerts\RuleRegistry;
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
			[ 'no_users', 'inactive', 'high_media', 'no_admin', 'missing_theme', 'updates_pending', 'insecure_url', 'disk_quota', 'heavy_autoload', 'search_hidden', 'cron_overdue' ],
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

	public function test_missing_theme_checks_the_active_theme_then_its_parent(): void {
		$rule = new MissingThemeRule( new NetworkState() );

		// twentytwentyfive est livré avec WordPress 6.9, 7.1 et trunk : installé localement comme en CI.
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'theme_stylesheet' => 'twentytwentyfive', 'theme_template' => 'twentytwentyfive' ] ), [], time() ) );
		$gone = $rule->evaluate( $this->build_record( [ 'theme_stylesheet' => 'msradar-gone', 'theme_template' => 'msradar-gone' ] ), [], time() );
		$this->assertSame( [ 'theme' => 'msradar-gone', 'missing' => 'theme' ], $gone->args );
		$orphan = $rule->evaluate( $this->build_record( [ 'theme_stylesheet' => 'twentytwentyfive', 'theme_template' => 'msradar-gone-parent' ] ), [], time() );
		$this->assertSame( [ 'theme' => 'msradar-gone-parent', 'missing' => 'parent' ], $orphan->args );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'theme_stylesheet' => '' ] ), [], time() ), 'No theme read: nothing to say.' );
		$this->assertSame( 'The active theme "msradar-gone" is not installed.', $rule->message( $gone->args ) );
		$this->assertSame( 'The parent theme "msradar-gone-parent" of the active theme is not installed.', $rule->message( $orphan->args ) );
	}

	public function test_updates_pending_counts_the_site_plugins_and_its_themes_only(): void {
		set_site_transient(
			'update_plugins',
			(object) [
				'response' => [
					'akismet/akismet.php'  => (object) [ 'new_version' => '9.0' ],
					'netwide/netwide.php'  => (object) [ 'new_version' => '2.0' ],
				],
			]
		);
		set_site_transient( 'update_themes', (object) [ 'response' => [ 'msradar-parent' => [ 'new_version' => '2.0' ] ] ] );
		$rule = new UpdatesPendingRule( new NetworkState() );
		$site = fn ( array $plugins, string $stylesheet, string $template ): SiteRecord => $this->build_record(
			[
				'theme_stylesheet' => $stylesheet,
				'theme_template'   => $template,
				'data'             => [ 'plugins_local' => $plugins ],
			]
		);

		$alert = $rule->evaluate( $site( [ 'akismet/akismet.php', 'hello.php' ], 'msradar-child', 'msradar-parent' ), [], time() );
		$this->assertSame( [ 'plugins' => 1, 'themes' => 1 ], $alert->args );
		$this->assertNull( $rule->evaluate( $site( [ 'hello.php' ], 'msradar-child', 'msradar-child' ), [], time() ), 'netwide/netwide.php is activated on the network, not in plugins_local: it does not count.' );
		$this->assertSame( 'Updates available for 1 plugin and 1 theme', $rule->message( [ 'plugins' => 1, 'themes' => 1 ] ) );
		$this->assertSame( 'Updates available for 3 plugins', $rule->message( [ 'plugins' => 3, 'themes' => 0 ] ) );
		$this->assertSame( 'Updates available for 2 themes', $rule->message( [ 'plugins' => 0, 'themes' => 2 ] ) );
	}

	public function test_insecure_url_only_on_an_https_network(): void {
		$state = new NetworkState();
		$rule  = new InsecureUrlRule( $state );
		$http  = $this->build_record( [ 'url' => 'http://example.org/rh/', 'siteurl' => 'http://example.org/rh' ] );

		update_blog_option( get_main_site_id(), 'home', 'http://example.org' );
		$this->assertNull( $rule->evaluate( $http, [], time() ), 'The network itself is in http.' );

		update_blog_option( get_main_site_id(), 'home', 'https://example.org' );
		$state->reset();
		$this->assertSame( [ 'url' => 'http://example.org/rh/' ], $rule->evaluate( $http, [], time() )->args );
		$mixed = $this->build_record( [ 'url' => 'https://example.org/rh/', 'siteurl' => 'http://example.org/rh' ] );
		$this->assertSame( [ 'url' => 'http://example.org/rh' ], $rule->evaluate( $mixed, [], time() )->args, 'The WordPress address counts too.' );
		$this->assertNull( $rule->evaluate( $this->build_record( [ 'url' => 'https://example.org/rh/', 'siteurl' => 'https://example.org/rh' ] ), [], time() ) );
		$this->assertSame( 'The address http://example.org/rh/ uses http while the network uses https.', $rule->message( [ 'url' => 'http://example.org/rh/' ] ) );
	}

	public function test_disk_quota_uses_the_site_quota_then_the_network_one(): void {
		update_site_option( 'upload_space_check_disabled', 0 );
		update_site_option( 'blog_upload_space', 10 );
		$rule = new DiskQuotaRule( new NetworkState() );
		$site = fn ( ?int $bytes, array $data = [] ): SiteRecord => $this->build_record(
			[
				'disk_bytes' => $bytes,
				'data'       => $data,
			]
		);

		$alert = $rule->evaluate( $site( 9 * MB_IN_BYTES ), [ 'percent' => 90 ], time() );
		$this->assertSame( [ 'used_mb' => 9, 'quota_mb' => 10 ], $alert->args );
		$this->assertNull( $rule->evaluate( $site( 8 * MB_IN_BYTES ), [ 'percent' => 90 ], time() ) );
		$this->assertNull( $rule->evaluate( $site( 9 * MB_IN_BYTES, [ 'options' => [ 'upload_space_mb' => 100 ] ] ), [ 'percent' => 90 ], time() ), 'The own quota of the site wins.' );
		$this->assertNull( $rule->evaluate( $site( null ), [ 'percent' => 90 ], time() ), 'Disk not measured: no alert.' );
		$this->assertSame( '9 MB used of the 10 MB upload quota', $rule->message( $alert->args ) );

		update_site_option( 'upload_space_check_disabled', 1 );
		$this->assertNull( $rule->evaluate( $site( 50 * MB_IN_BYTES ), [ 'percent' => 90 ], time() ), 'Quotas are disabled on the network.' );
	}
}
