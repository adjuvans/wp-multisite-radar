<?php
namespace MultisiteRadar\Tests\Cli;

use MultisiteRadar\Cli\Rows;
use MultisiteRadar\Tests\TestCase;

final class RowsTest extends TestCase {

	public function test_a_site_row_has_one_scalar_per_column(): void {
		$row = Rows::site(
			[
				'id'          => 5,
				'name'        => 'Blog',
				'theme'       => [
					'stylesheet' => 'child',
					'template'   => 'parent',
				],
				'status'      => [
					'public'   => true,
					'archived' => false,
					'spam'     => true,
					'deleted'  => false,
				],
				'alert_rules' => [ 'inactive', 'no_admin' ],
			]
		);

		$this->assertSame(
			[
				'id'          => 5,
				'name'        => 'Blog',
				'theme'       => 'child',
				'status'      => 'public,spam',
				'alert_rules' => 'inactive,no_admin',
			],
			$row
		);
	}

	public function test_an_alert_row_names_its_site(): void {
		$row = Rows::alert(
			[
				'id'       => '5:inactive',
				'site'     => [
					'id'        => 5,
					'name'      => 'Blog',
					'url'       => 'http://example.org/blog/',
					'admin_url' => 'http://example.org/blog/wp-admin/',
				],
				'rule'     => 'inactive',
				'label'    => 'Inactive site',
				'severity' => 'warning',
				'message'  => 'No activity for 7 months.',
			]
		);

		$this->assertSame(
			[
				'site_id'  => 5,
				'site'     => 'Blog',
				'url'      => 'http://example.org/blog/',
				'rule'     => 'inactive',
				'label'    => 'Inactive site',
				'severity' => 'warning',
				'message'  => 'No activity for 7 months.',
			],
			$row
		);
	}
}
