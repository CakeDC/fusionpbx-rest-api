<?php
namespace RestApi\Test\Http;

use RestApi\Test\Support\CdrSample;
use RestApi\Test\Support\RestApiTestCase;

/** cdr-search and cdr-details through rest.php: the HTTP status is really sent (#43937). */
class RestCdrTest extends RestApiTestCase
{
	protected function tables(): array
	{
		return parent::tables() + array('v_xml_cdr' => CdrSample::rows());
	}

	public function testSearchesCalls(): void
	{
		$response = $this->api(array('action' => 'cdr-search', 'per_page' => 2));

		$this->assertSame(200, $response['status']);
		$this->assertSame(array(CdrSample::A7, CdrSample::B6), array_column($this->json($response)['data'], 'xml_cdr_uuid'));
		$this->assertSame(array('page' => 1, 'per_page' => 2, 'total' => 7), $this->json($response)['pagination']);
	}

	public function testSearchAnswers400ForAnInvalidParameter(): void
	{
		$response = $this->api(array('action' => 'cdr-search', 'per_page' => 500));

		$this->assertSame(400, $response['status']);
		$this->assertSame(array('error' => 'invalid per_page'), $this->json($response));
	}

	public function testSearchNeedsXmlCdrView(): void
	{
		$this->grantOnly(array('extension_view'));

		$response = $this->api(array('action' => 'cdr-search'));

		$this->assertSame(403, $response['status']);
		$this->assertSame(array('error' => 'forbidden', 'missing_permissions' => array('xml_cdr_view')), $this->json($response));
	}

	public function testReturnsACallWithItsLegs(): void
	{
		$response = $this->api(array('action' => 'cdr-details', 'xml_cdr_uuid' => CdrSample::B2));

		$this->assertSame(200, $response['status']);
		$this->assertSame(CdrSample::A2, $this->json($response)['xml_cdr_uuid']);
		$this->assertSame(array(CdrSample::A2, CdrSample::B2), array_column($this->json($response)['legs'], 'xml_cdr_uuid'));
	}

	public function testDetailsAnswers404ForACallOfAnotherDomain(): void
	{
		$response = $this->api(array('action' => 'cdr-details', 'xml_cdr_uuid' => CdrSample::X1));

		$this->assertSame(404, $response['status']);
		$this->assertSame(array('error' => 'call not found'), $this->json($response));
	}

	public function testDetailsAnswers400WithoutAUuid(): void
	{
		$response = $this->api(array('action' => 'cdr-details'));

		$this->assertSame(400, $response['status']);
		$this->assertSame(array('error' => 'invalid xml_cdr_uuid'), $this->json($response));
	}

	public function testDetailsNeedsXmlCdrView(): void
	{
		$this->grantOnly(array('extension_view'));

		$response = $this->api(array('action' => 'cdr-details', 'xml_cdr_uuid' => CdrSample::A1));

		$this->assertSame(403, $response['status']);
		$this->assertSame(array('error' => 'forbidden', 'missing_permissions' => array('xml_cdr_view')), $this->json($response));
	}
}
