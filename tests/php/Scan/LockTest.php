<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Scan\Lock;
use MultisiteRadar\Tests\TestCase;

final class LockTest extends TestCase {

	private function expire_now(): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->get_blog_prefix( get_main_site_id() ) . 'options',
			[ 'option_value' => (string) ( time() - 1 ) ],
			[ 'option_name' => Lock::NAME ]
		);
	}

	public function test_acquire_is_exclusive_until_released(): void {
		$first  = new Lock();
		$second = new Lock();

		$this->assertTrue( $first->acquire() );
		$this->assertFalse( $second->acquire() );
		$this->assertTrue( $second->is_locked() );

		$first->release();
		$this->assertFalse( $first->is_locked() );
		$this->assertTrue( $second->acquire() );
		$second->release();
	}

	public function test_an_expired_lock_is_taken_over(): void {
		( new Lock() )->acquire();
		$this->expire_now();

		$this->assertFalse( ( new Lock() )->is_locked() );
		$this->assertTrue( ( new Lock() )->acquire() );
	}

	public function test_refresh_extends_the_lock(): void {
		$lock = new Lock( 60 );
		$lock->acquire();
		$this->expire_now();

		$lock->refresh();

		$this->assertTrue( $lock->is_locked() );
	}
}
