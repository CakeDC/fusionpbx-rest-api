<?php
namespace RestApi\Test\Http;

use RestApi\Test\Support\RestApiTestCase;

class RestRoutingTest extends RestApiTestCase
{
	protected static function extraFiles(): array
	{
		$marker = '<?php echo "OUTSIDE-CODE-RAN";';
		return array(
			'outside.php' => $marker,
			'app/outside.php' => $marker,
			// an app exposing an action through its own app_api.php mapping
			'app/call_stats/app_api.php' => '<?php $app_api["call_stats"]["call-stats"] = "api/stats.php";',
			'app/call_stats/api/stats.php' => '<?php $required_params = array(); $required_permissions = array(); function do_action($body) { return array("calls" => 42); }',
			// an app without an API
			'app/no_api/index.php' => '<?php',
			// an app whose mapping points outside its own directory
			'app/rogue/app_api.php' => '<?php $app_api["rogue"]["steal"] = "../outside.php";',
		);
	}

	public function testRunsAPluginAction(): void
	{
		$response = $this->api(array('action' => 'Domain-Details', 'domain_name' => 'tenant1.example.com'));

		$this->assertSame(200, $response['status']);
		$this->assertSame('tenant1.example.com', $this->json($response)['domain_name']);
	}

	public function testRunsAnActionMappedByAnotherApp(): void
	{
		$response = $this->api(array('action' => 'call-stats', 'app' => 'call_stats'));

		$this->assertSame(200, $response['status']);
		$this->assertSame(array('calls' => 42), $this->json($response));
	}

	public function testRejectsAnActionPathOutsideTheActionsDirectory(): void
	{
		foreach (array('../../outside', '../../../outside', "domain-details\n") as $action) {
			$response = $this->api(array('action' => $action, 'domain_name' => 'tenant1.example.com'));

			$this->assertSame(400, $response['status'], json_encode($action));
			$this->assertStringNotContainsString('OUTSIDE-CODE-RAN', $response['body']);
		}
	}

	public function testRejectsAnAppPathOutsideTheAppsDirectory(): void
	{
		$response = $this->api(array('action' => 'call-stats', 'app' => '../app/call_stats'));

		$this->assertSame(400, $response['status']);
		$this->assertSame(array('error' => 'unknown app'), $this->json($response));
	}

	public function testRejectsAnAppMappingThatLeavesTheAppDirectory(): void
	{
		$response = $this->api(array('action' => 'steal', 'app' => 'rogue'));

		$this->assertSame(400, $response['status']);
		$this->assertStringNotContainsString('OUTSIDE-CODE-RAN', $response['body']);
	}

	public function testRejectsAnUnknownAction(): void
	{
		$response = $this->api(array('action' => 'does-not-exist'));

		$this->assertSame(400, $response['status']);
		$this->assertSame(array('error' => 'unknown action'), $this->json($response));
	}

	public function testRejectsABodyThatIsNotAJsonObject(): void
	{
		foreach (array('"domain-details"', 'not json', '[1, 2]') as $body) {
			$this->assertSame(400, $this->api($body)['status'], $body);
		}
	}

	public function testListsMissingRequiredParameters(): void
	{
		$response = $this->api(array('action' => 'originate', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001'));

		$this->assertSame(400, $response['status']);
		$this->assertSame(array('caller_id_number', 'destination_a', 'destination_b'), $this->json($response)['error']['missing_parameters']);
	}

	public function testReturnsTheStatusCodeChosenByTheAction(): void
	{
		$response = $this->api(array('action' => 'domain-details', 'domain_name' => 'unknown.example.com'));

		$this->assertSame(404, $response['status']);
		$this->assertSame(array('error' => 'domain not found'), $this->json($response));
	}

	public function testRequiresAnAction(): void
	{
		$response = $this->api(array('domain_name' => 'tenant1.example.com'));

		$this->assertSame(400, $response['status']);
		$this->assertSame(array('action'), $this->json($response)['error']['missing_parameters']);
	}

	public function testRejectsAnAppWithoutAnApi(): void
	{
		$response = $this->api(array('action' => 'call-stats', 'app' => 'no_api'));

		$this->assertSame(400, $response['status']);
		$this->assertSame(array('error' => 'unknown app'), $this->json($response));
	}

	public function testReturnsServerErrorWhenAnActionFailsWithoutAStatusCode(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_extensions'][] = array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000100', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension' => '100');
		});

		$response = $this->api(array('action' => 'extension-create', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension' => '100'));

		$this->assertSame(500, $response['status']);
		$this->assertSame(array('error' => 'extension already exists'), $this->json($response));
	}
}
