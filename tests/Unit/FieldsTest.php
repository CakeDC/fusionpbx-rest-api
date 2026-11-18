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

	public static function queueValues(): array
	{
		return array(
			'epoch as Postgres returns a numeric' => array('cc_queue_joined_epoch', '1788858000', 1788858000),
			'epoch as an integer' => array('cc_queue_answered_epoch', 1788858012, 1788858012),
			'epoch with decimals' => array('cc_queue_canceled_epoch', '1788861640.000', 1788861640),
			'empty epoch' => array('cc_queue_joined_epoch', '', null),
			'unset epoch' => array('cc_queue_answered_epoch', '_undef_', null),
			'zero epoch' => array('cc_queue_canceled_epoch', '0', null),
			'null epoch' => array('cc_queue_canceled_epoch', null, null),
			'side' => array('cc_side', 'member', 'member'),
			'empty side' => array('cc_side', '', null),
			'unset cancel reason' => array('cc_cancel_reason', '_undef_', null),
			'cancel reason' => array('cc_cancel_reason', 'NO_AGENT_TIMEOUT', 'NO_AGENT_TIMEOUT'),
			'empty cause' => array('cc_cause', '', null),
		);
	}

	#[DataProvider('queueValues')]
	public function testFormatsTheQueueValuesOfACdr(string $column, $stored, $expected): void
	{
		$cdr = array_fill_keys(REST_API_CDR_FIELDS, null);
		$cdr[$column] = $stored;

		$this->assertSame($expected, rest_api_format_cdr($cdr)[$column]);
	}
}
