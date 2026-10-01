<?php
namespace MultisiteRadar\Tests\Alerts;

use MultisiteRadar\Alerts\Alert;
use MultisiteRadar\Alerts\Rules\HighMediaRule;
use MultisiteRadar\Alerts\Rules\InactiveRule;
use MultisiteRadar\Alerts\Rules\NoUsersRule;
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
		$this->assertSame( '1000 media files (threshold: 1000)', $rule->message( $alert->args ) );
	}

	public function test_default_params_satisfy_their_schema(): void {
		foreach ( [ new NoUsersRule(), new InactiveRule(), new HighMediaRule() ] as $rule ) {
			$this->assertTrue( rest_validate_value_from_schema( $rule->default_params(), $rule->params_schema(), 'params' ) );
		}
	}
}
