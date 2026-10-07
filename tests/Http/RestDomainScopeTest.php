<?php
namespace RestApi\Test\Http;

use RestApi\Test\Support\RestApiTestCase;

/**
 * Requests act on the key user's domain, or on another domain when the user
 * has domain_select (#43940).
 */
class RestDomainScopeTest extends RestApiTestCase
{
	private const MISSING_DOMAIN = 'aaaaaaaa-0000-4000-8000-00000000ffff';

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_extensions' => array(
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000100', 'domain_uuid' => self::DOMAIN_UUID, 'extension' => '100', 'emergency_caller_id_number' => ''),
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000200', 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'extension' => '200', 'emergency_caller_id_number' => ''),
			),
			'v_destinations' => array(
				array('destination_uuid' => 'bbbbbbbb-0000-4000-8000-000000000002', 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'destination_number' => '5552000', 'destination_actions' => null),
			),
		);
	}

	private function allowOtherDomains(): void
	{
		$this->grantOnly(array_merge(self::ACTION_PERMISSIONS, array('domain_select')));
	}

	private function extensions(array $response): array
	{
		return array_column($this->json($response), 'extension');
	}

	public function testActsOnTheUsersDomainByDefault(): void
	{
		$response = $this->api(array('action' => 'extension-list'));

		$this->assertSame(200, $response['status']);
		$this->assertSame(array('100'), $this->extensions($response));
	}

	public function testAcceptsTheUsersOwnDomainInAnyCase(): void
	{
		$response = $this->api(array('action' => 'extension-list', 'domain_uuid' => strtoupper(self::DOMAIN_UUID)));

		$this->assertSame(200, $response['status']);
		$this->assertSame(array('100'), $this->extensions($response));
	}

	public function testRejectsAnotherDomainWithoutDomainSelect(): void
	{
		foreach (array(self::OTHER_DOMAIN_UUID, self::MISSING_DOMAIN) as $domain_uuid) {
			$response = $this->api(array('action' => 'extension-list', 'domain_uuid' => $domain_uuid));

			$this->assertSame(403, $response['status'], $domain_uuid);
			$this->assertSame(array('error' => 'forbidden'), $this->json($response));
		}
	}

	public function testActsOnAnotherDomainWithDomainSelect(): void
	{
		$this->allowOtherDomains();

		$response = $this->api(array('action' => 'extension-list', 'domain_uuid' => self::OTHER_DOMAIN_UUID));

		$this->assertSame(array('200'), $this->extensions($response));
	}

	public function testReportsAMissingOrDisabledDomainToUsersWithDomainSelect(): void
	{
		$this->allowOtherDomains();
		$this->updateRows('v_domains', array('domain_uuid' => self::OTHER_DOMAIN_UUID), array('domain_enabled' => 'false'));

		foreach (array(self::OTHER_DOMAIN_UUID, self::MISSING_DOMAIN) as $domain_uuid) {
			$response = $this->api(array('action' => 'extension-list', 'domain_uuid' => $domain_uuid));

			$this->assertSame(404, $response['status'], $domain_uuid);
			$this->assertSame(array('error' => 'domain not found'), $this->json($response));
		}
	}

	public function testRejectsADomainUuidThatIsNotAUuid(): void
	{
		foreach (array('tenant2', array(self::OTHER_DOMAIN_UUID), 42) as $domain_uuid) {
			$response = $this->api(array('action' => 'extension-list', 'domain_uuid' => $domain_uuid));

			$this->assertSame(400, $response['status'], json_encode($domain_uuid));
			$this->assertSame(array('error' => 'invalid domain_uuid'), $this->json($response));
		}
	}

	// domain-list (#43969) lists other domains only for domain_select, the
	// same rule rest.php applies to domain_uuid
	public function testDomainListOnlyListsOtherDomainsWithDomainSelect(): void
	{
		$own = $this->api(array('action' => 'domain-list'));
		$this->allowOtherDomains();
		$all = $this->api(array('action' => 'domain-list'));

		$this->assertSame(200, $own['status']);
		$this->assertSame(array('tenant1.example.com'), array_column($this->json($own)['data'], 'domain_name'));
		$this->assertSame(200, $all['status']);
		$this->assertSame(array('tenant1.example.com', 'tenant2.example.com'), array_column($this->json($all)['data'], 'domain_name'));
	}

	public function testDomainDetailsReturnsTheUsersDomainByDefault(): void
	{
		$response = $this->api(array('action' => 'domain-details'));

		$this->assertSame(200, $response['status']);
		$this->assertSame('tenant1.example.com', $this->json($response)['domain_name']);
	}

	public function testDomainDetailsOnlyFindsOtherDomainsByNameWithDomainSelect(): void
	{
		$lookup = array('action' => 'domain-details', 'domain_name' => 'tenant2.example.com');

		$response = $this->api($lookup);
		$this->assertSame(404, $response['status']);
		$this->assertSame(array('error' => 'domain not found'), $this->json($response));

		$this->allowOtherDomains();
		$this->assertSame(self::OTHER_DOMAIN_UUID, $this->json($this->api($lookup))['domain_uuid']);
	}

	public function testDomainDetailsRejectsANameThatIsNotAString(): void
	{
		$response = $this->api(array('action' => 'domain-details', 'domain_name' => array('tenant1.example.com')));

		$this->assertSame(404, $response['status']);
	}

	public function testDestinationDetailsSearchesAllDomainsOnlyForDomainSelectWithoutADomain(): void
	{
		$lookup = array('action' => 'destination-details', 'number' => '5552000');

		$this->assertSame(404, $this->api($lookup)['status']);

		$this->allowOtherDomains();
		$this->assertSame('bbbbbbbb-0000-4000-8000-000000000002', $this->json($this->api($lookup))['destination_uuid']);
		$this->assertSame(404, $this->api($lookup + array('domain_uuid' => self::DOMAIN_UUID))['status']);
	}
}
