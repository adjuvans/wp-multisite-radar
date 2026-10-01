<?php
namespace MultisiteRadar\Tests\Scan;

use MultisiteRadar\Scan\Lock;
use MultisiteRadar\Tests\TestCase;

final class LockTest extends TestCase {

	private function expire_now(): void {
		global $wpdb;
		$table = $wpdb->get_blog_prefix( get_main_site_id() ) . 'options';
		$value = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, Lock::NAME ) );
		$wpdb->update(
			$table,
			[ 'option_value' => ( time() - 1 ) . ':' . substr( strstr( $value, ':' ), 1 ) ],
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

	public function test_a_lock_taken_over_by_another_process_is_not_refreshed_or_released_by_the_old_owner(): void {
		$old = new Lock();
		$new = new Lock();
		$old->acquire();
		$this->expire_now();
		$this->assertTrue( $new->acquire() );

		$this->assertFalse( $old->refresh() );
		$old->release();

		$this->assertTrue( $new->is_locked() );
		$this->assertTrue( $new->refresh() );
		$new->release();
	}
}
