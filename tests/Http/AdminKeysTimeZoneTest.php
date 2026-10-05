<?php
namespace RestApi\Test\Http;

use RestApi\Test\Support\AdminKeysHelpers;
use RestApi\Test\Support\RestApiTestCase;

/**
 * key_edit.php with PHP in a non-UTC time zone: the stored expiry must keep its instant (#43940).
 */
class AdminKeysTimeZoneTest extends RestApiTestCase
{
	use AdminKeysHelpers;

	protected static function phpSettings(): array
	{
		return array('date.timezone' => 'America/Mexico_City');
	}

	private function expiresField(string $body): string
	{
		$this->assertMatchesRegularExpression('/type="datetime-local" step="1" name="expires" value="([^"]*)"/', $body);
		preg_match('/type="datetime-local" step="1" name="expires" value="([^"]*)"/', $body, $m);
		return $m[1];
	}

	private function editFields(string $expires, array $form): array
	{
		return array('name' => 'billing', 'key_uuid' => self::KEY_ID, 'user_uuid' => self::USER_UUID, 'key_enabled' => 'true', 'expires' => $expires) + $this->tokenField($form);
	}

	public function testStoresTheExpiryWithAnOffsetAndKeepsItWhenResavingTheForm(): void
	{
		$this->login();
		$form = $this->page('key_edit.php?key_uuid='.self::KEY_ID);

		$this->submit('key_edit.php', $this->editFields('2030-06-15T12:00:30', $form));

		$stored = $this->keys()[0]['expires'];
		$this->assertMatchesRegularExpression('/(Z|[+-]\d{2}:?\d{2})$/', $stored);
		// 12:00:30 in Mexico City (UTC-6, no DST in 2030)
		$this->assertSame(gmmktime(18, 0, 30, 6, 15, 2030), strtotime($stored));

		$form = $this->page('key_edit.php?key_uuid='.self::KEY_ID);
		$rendered = $this->expiresField($form['body']);
		$this->assertSame('2030-06-15T12:00:30', $rendered);
		$this->submit('key_edit.php', $this->editFields($rendered, $form));
		$this->assertSame(gmmktime(18, 0, 30, 6, 15, 2030), strtotime($this->keys()[0]['expires']));
	}

	public function testShowsAWarningInsteadOfAnEpochDateForAnUnreadableExpiry(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['rest_api_keys'][0]['expires'] = 'garbage<b>';
		});
		$this->login();

		$body = $this->page('key_edit.php?key_uuid='.self::KEY_ID)['body'];

		$this->assertSame('', $this->expiresField($body));
		$this->assertStringContainsString('stored expiry could not be read: garbage&lt;b&gt;', $body);
		$this->assertStringNotContainsString('1970', $body);
	}
}
