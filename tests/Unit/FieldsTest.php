<?php
namespace RestApi\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RestApi\Test\Support\FusionPbxSchema;

// fake_sql() doesn't check column names, but PostgreSQL refuses a SELECT of a
// column the table doesn't have, so the returned columns are pinned to 5.6.5's
class FieldsTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		require_once PLUGIN_DIR.'/lib/fields.php';
	}

	public static function fieldLists(): array
	{
		return array(
			'REST_API_EXTENSION_FIELDS' => array('REST_API_EXTENSION_FIELDS', 'v_extensions'),
			'REST_API_USER_EXTENSION_FIELDS' => array('REST_API_USER_EXTENSION_FIELDS', 'v_extensions'),
			'REST_API_DESTINATION_FIELDS' => array('REST_API_DESTINATION_FIELDS', 'v_destinations'),
			'REST_API_DOMAIN_FIELDS' => array('REST_API_DOMAIN_FIELDS', 'v_domains'),
			'REST_API_DOMAIN_LIST_FIELDS' => array('REST_API_DOMAIN_LIST_FIELDS', 'v_domains'),
			'REST_API_USER_FIELDS' => array('REST_API_USER_FIELDS', 'v_users'),
			'REST_API_CDR_FIELDS' => array('REST_API_CDR_FIELDS', 'v_xml_cdr'),
		);
	}

	#[DataProvider('fieldLists')]
	public function testFieldsExistInFusionPbx(string $list, string $table): void
	{
		$this->assertSame(array(), array_values(array_diff(constant($list), FusionPbxSchema::columns($table))));
	}

	// a new list must be added above
	public function testEveryFieldListIsChecked(): void
	{
		$lists = array_filter(array_keys(get_defined_constants(true)['user']), function ($name) {
			return preg_match('/^REST_API_\w+_FIELDS$/', $name) === 1;
		});

		$this->assertEqualsCanonicalizing(array_keys(self::fieldLists()), array_values($lists));
	}
}
