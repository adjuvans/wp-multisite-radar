<?php
namespace MultisiteRadar\Tests\Rest;

use MultisiteRadar\Tests\RestTestCase;

final class AlertRulesControllerTest extends RestTestCase {

	public function test_requires_the_view_capability(): void {
		$this->assertSame( 401, $this->request( 'GET', '/alert-rules' )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/alert-rules' )->get_status() );
	}

	public function test_lists_every_rule_with_its_defaults_and_its_parameter_schema(): void {
		$this->login_as_super_admin();

		$response = $this->request( 'GET', '/alert-rules' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );
		$this->assertSame( array_keys( $this->plugin()->rules()->all() ), $ids );
		$inactive = $data[ (int) array_search( 'inactive', $ids, true ) ];
		$this->assertSame( [ 'id', 'label', 'description', 'default_severity', 'default_params', 'params_schema' ], array_keys( $inactive ) );
		$this->assertSame( 'Inactive site', $inactive['label'] );
		$this->assertSame( 'warning', $inactive['default_severity'] );
		$this->assertSame( [ 'months' => 6 ], $inactive['default_params'] );
		$this->assertSame( 120, $inactive['params_schema']['properties']['months']['maximum'] );
		$this->assertSame( 'Months without activity', $inactive['params_schema']['properties']['months']['title'] );
	}

	public function test_rules_without_parameters_are_described_by_empty_objects(): void {
		$this->login_as_super_admin();

		$json = (string) wp_json_encode( $this->request( 'GET', '/alert-rules' )->get_data() );

		$this->assertStringContainsString( '"id":"no_users"', $json );
		$this->assertStringContainsString( '"default_params":{},"params_schema":{"type":"object","additionalProperties":false,"properties":{}}', $json );
		$this->assertStringNotContainsString( '"default_params":[]', $json );
	}
}
