<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * ringgroup-update.
 */
#[RunTestsInSeparateProcesses]
class RingGroupUpdateTest extends ActionTestCase
{
	private const SALES = '99999999-0000-4000-8000-000000000001';
	private const OTHER = '99999999-0000-4000-8000-000000000020';
	private const DIALPLAN = 'cccccccc-0000-4000-8000-000000000600';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';
	private const ALL_PERMISSIONS = array('ring_group_edit', 'dialplan_edit', 'ring_group_destination_add', 'ring_group_destination_delete', 'ring_group_view', 'ring_group_destination_view');

	protected function action(): string
	{
		return 'ringgroup-update';
	}

	// as FusionPBX's ring_group_edit.php writes it
	private static function dialplanXml(string $name): string
	{
		return "<extension name=\"".$name."\" continue=\"\" uuid=\"".self::DIALPLAN."\">\n"
			."\t<condition field=\"destination_number\" expression=\"^600$\">\n"
			."\t\t<action application=\"ring_ready\" data=\"\"/>\n"
			."\t\t<action application=\"set\" data=\"ring_group_uuid=".self::SALES."\"/>\n"
			."\t\t<action application=\"lua\" data=\"app.lua ring_groups\"/>\n"
			."\t</condition>\n"
			."</extension>\n";
	}

	private static function destination(string $uuid, string $ring_group, string $number, string $delay, string $timeout = '30'): array
	{
		return array('ring_group_destination_uuid' => $uuid, 'domain_uuid' => self::DOMAIN_UUID, 'ring_group_uuid' => $ring_group, 'destination_number' => $number, 'destination_delay' => $delay, 'destination_timeout' => $timeout, 'destination_prompt' => '', 'destination_enabled' => 'true');
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_ring_groups' => array(
				array('ring_group_uuid' => self::SALES, 'domain_uuid' => self::DOMAIN_UUID, 'ring_group_name' => 'Sales', 'ring_group_extension' => '600', 'ring_group_strategy' => 'simultaneous', 'ring_group_context' => 'tenant1.example.com', 'dialplan_uuid' => self::DIALPLAN),
				array('ring_group_uuid' => self::OTHER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'ring_group_name' => 'Sales', 'ring_group_extension' => '600', 'ring_group_strategy' => 'simultaneous', 'ring_group_context' => 'tenant2.example.com', 'dialplan_uuid' => null),
			),
			'v_ring_group_destinations' => array(
				self::destination('d0000000-0000-4000-8000-000000000001', self::SALES, '101', '0'),
				self::destination('d0000000-0000-4000-8000-000000000002', self::SALES, '102', '10', '45'),
				self::destination('d0000000-0000-4000-8000-000000000020', self::OTHER, '101', '0'),
			),
			'v_dialplans' => array(
				array('dialplan_uuid' => self::DIALPLAN, 'domain_uuid' => self::DOMAIN_UUID, 'dialplan_name' => 'Sales', 'dialplan_xml' => self::dialplanXml('Sales')),
			),
		);
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->grantOnly(self::ALL_PERMISSIONS);
	}

	private function update(array $fields, string $ring_group_uuid = self::SALES): array
	{
		return $this->runAction($fields + array('domain_uuid' => self::DOMAIN_UUID, 'ring_group_uuid' => $ring_group_uuid));
	}

	private function row(string $table, string $key, string $value): array
	{
		foreach ($this->state()['tables'][$table] as $row) {
			if ($row[$key] === $value) {
				return $row;
			}
		}
		$this->fail("no $table row with $key = $value");
	}

	private function destinations(string $ring_group_uuid = self::SALES): array
	{
		$rows = array_filter($this->state()['tables']['v_ring_group_destinations'], function ($row) use ($ring_group_uuid) {
			return $row['ring_group_uuid'] === $ring_group_uuid;
		});
		$numbers = array();
		foreach ($rows as $row) {
			$numbers[$row['destination_number']] = array($row['destination_delay'], $row['destination_timeout']);
		}
		ksort($numbers);
		return $numbers;
	}

	// the name is also in the dialplan, escaped as FusionPBX's xml::sanitize() does
	public function testRenamesTheRingGroupAndItsDialplan(): void
	{
		$result = $this->update(array('name' => 'Sales & Support'));

		$this->assertSame('Sales & Support', $result['name']);
		$this->assertSame('Sales & Support', $this->row('v_ring_groups', 'ring_group_uuid', self::SALES)['ring_group_name']);
		$dialplan = $this->row('v_dialplans', 'dialplan_uuid', self::DIALPLAN);
		$this->assertSame('Sales & Support', $dialplan['dialplan_name']);
		$this->assertSame(self::dialplanXml('Sales &amp; Support'), $dialplan['dialplan_xml']);
	}

	// quotes too, or a name could close the attribute and add others
	public function testEscapesQuotesInTheDialplanName(): void
	{
		$this->update(array('name' => 'x" continue="true'));

		$this->assertSame(self::dialplanXml('x&quot; continue=&quot;true'), $this->row('v_dialplans', 'dialplan_uuid', self::DIALPLAN)['dialplan_xml']);
	}

	public function testRenamesARingGroupWithoutADialplan(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_ring_groups'][0]['dialplan_uuid'] = null;
		});

		$this->assertSame('Support', $this->update(array('name' => 'Support'))['name']);
		$this->assertSame(self::dialplanXml('Sales'), $this->row('v_dialplans', 'dialplan_uuid', self::DIALPLAN)['dialplan_xml']);
	}

	// FusionPBX's ring group script reads the strategy from the database
	public function testChangesTheStrategyOnly(): void
	{
		$result = $this->update(array('strategy' => 'sequence'));

		$this->assertSame('sequence', $result['strategy']);
		$this->assertSame('Sales', $result['name']);
		$this->assertSame('sequence', $this->row('v_ring_groups', 'ring_group_uuid', self::SALES)['ring_group_strategy']);
		$this->assertSame(self::dialplanXml('Sales'), $this->row('v_dialplans', 'dialplan_uuid', self::DIALPLAN)['dialplan_xml']);
		$this->assertSame(array('101' => array('0', '30'), '102' => array('10', '45')), $this->destinations());
	}

	// numbers that stay keep their delay and timeout, new ones get
	// ringgroup-create's defaults, the others go
	public function testSetsWhichNumbersRing(): void
	{
		$result = $this->update(array('destinations' => array(array('number' => '103'), array('number' => '102'))));

		$this->assertSame(array('102' => array('10', '45'), '103' => array('0', '30')), $this->destinations());
		$this->assertSame(array(array('number' => '103'), array('number' => '102')), $result['destinations']);
		$this->assertSame(array('101' => array('0', '30')), $this->destinations(self::OTHER));
		$added = $this->row('v_ring_group_destinations', 'destination_number', '103');
		$this->assertSame(array(self::DOMAIN_UUID, 'true'), array($added['domain_uuid'], $added['destination_enabled']));
	}

	// JSON objects reach the action as objects
	public function testAcceptsDestinationsAsObjects(): void
	{
		$this->update(array('destinations' => array((object)array('number' => '103'))));

		$this->assertSame(array('103' => array('0', '30')), $this->destinations());
	}

	public function testANumberGivenTwiceRingsOnce(): void
	{
		$this->update(array('destinations' => array(array('number' => '103'), array('number' => '103'))));

		$this->assertSame(array('103' => array('0', '30')), $this->destinations());
	}

	public function testClearsTheDialplanCache(): void
	{
		$this->update(array('strategy' => 'sequence'));

		$this->assertSame(array('dialplan:tenant1.example.com'), $this->state()['cache_deleted']);
	}

	public function testAnswersNotFoundForARingGroupOfAnotherDomainOrAnUnknownOne(): void
	{
		foreach (array(self::OTHER, '99999999-0000-4000-8000-00000000ffff') as $ring_group_uuid) {
			$this->assertSame(array('error' => 'ring group not found', 'code' => 404), $this->update(array('strategy' => 'sequence'), $ring_group_uuid));
		}
		$this->assertSame(array(), $this->state()['saved']);
	}

	public static function invalidFields(): array
	{
		return array(
			'malformed ring_group_uuid' => array(array('ring_group_uuid' => '600', 'strategy' => 'sequence'), 'ring_group_uuid'),
			'empty name' => array(array('name' => ''), 'name'),
			'name with a newline' => array(array('name' => "Sales\nTeam"), 'name'),
			'name not a string' => array(array('name' => 42), 'name'),
			'unknown strategy' => array(array('strategy' => 'loudest'), 'strategy'),
			'no destinations' => array(array('destinations' => array()), 'destinations'),
			'destinations not a list' => array(array('destinations' => '[{"number":"101"}]'), 'destinations'),
			'destination without a number' => array(array('destinations' => array(array('extension' => '101'))), 'destinations'),
			'destination with a dialplan character' => array(array('destinations' => array(array('number' => '101;'))), 'destinations'),
		);
	}

	#[DataProvider('invalidFields')]
	public function testRejectsAnInvalidField(array $fields, string $name): void
	{
		$this->assertSame(array('error' => 'invalid '.$name, 'code' => 400), $this->update($fields));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testRejectsARequestWithoutAnyField(): void
	{
		$this->assertSame(array('error' => 'nothing to update', 'code' => 400), $this->update(array()));
	}

	public static function fieldPermissions(): array
	{
		return array(
			'name' => array(array('name' => 'Support'), array('dialplan_edit')),
			'destinations' => array(array('destinations' => array(array('number' => '103'))), array('ring_group_destination_add', 'ring_group_destination_delete')),
		);
	}

	// save() and delete() would silently skip what the user may not change
	#[DataProvider('fieldPermissions')]
	public function testRequiresThePermissionsOfWhatItChanges(array $fields, array $permissions): void
	{
		$this->grantOnly(array_values(array_diff(self::ALL_PERMISSIONS, $permissions)));

		$this->assertSame(array('error' => 'forbidden', 'missing_permissions' => $permissions, 'code' => 403), $this->update($fields));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->update(array('strategy' => 'sequence')));
	}

	// the old destinations are only deleted once the new ones are saved
	public function testAnswers500WhenTheSaveFails(): void
	{
		$this->failSaves();

		$this->assertSame(array('error' => 'error updating ring group', 'code' => 500), $this->update(array('destinations' => array(array('number' => '103')))));
		$this->assertSame(array('101' => array('0', '30'), '102' => array('10', '45')), $this->destinations());
		$this->assertSame(array(), $this->state()['cache_deleted']);
	}

	public function testAnswers500WhenTheDeleteFails(): void
	{
		\FakeStore::update(function (&$state) {
			$state['delete_fails'] = true;
		});

		$this->assertSame(array('error' => 'error updating ring group', 'code' => 500), $this->update(array('destinations' => array(array('number' => '103')))));
	}
}
