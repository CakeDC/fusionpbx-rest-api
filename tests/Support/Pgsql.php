<?php
namespace RestApi\Test\Support;

use FakeStore;
use PDO;
use PDOException;

/**
 * A real PostgreSQL behind the fake database class (database::$pgsql), so the
 * plugin's SQL runs on the server it is deployed with instead of fake_sql().
 * The database is named by REST_API_PGSQL_DSN, e.g.
 * "pgsql:host=127.0.0.1 port=5432 dbname=fusionpbx user=fusionpbx password=fusionpbx".
 *
 * v_xml_cdr has FusionPBX 5.6.5's columns and types (app/xml_cdr/app_config.php).
 * Before each query it is reloaded from the FakeStore rows when they changed,
 * so tests seed and edit rows as they do for fake_sql().
 */
final class Pgsql
{
	public const DSN_VARIABLE = 'REST_API_PGSQL_DSN';

	private const V_XML_CDR = 'CREATE TABLE IF NOT EXISTS v_xml_cdr (
		xml_cdr_uuid uuid PRIMARY KEY, domain_uuid uuid, provider_uuid uuid, extension_uuid uuid, v_id text,
		sip_call_id text, domain_name text, accountcode text, direction text, default_language text,
		context text, caller_id_name text, caller_id_number text, caller_destination text, source_number text,
		destination_number text, start_epoch numeric, start_stamp timestamptz, answer_stamp timestamptz,
		answer_epoch numeric, end_epoch numeric, end_stamp timestamptz, duration numeric,
		mduration numeric, billsec numeric, billmsec numeric, hold_accum_seconds numeric, bridge_uuid text,
		read_codec text, read_rate text, write_codec text, write_rate text, remote_media_ip text,
		network_addr text, record_path text, record_name text, record_length numeric, record_transcription text,
		leg char(1), originating_leg_uuid uuid, pdd_ms numeric, rtp_audio_in_mos numeric, last_app text,
		last_arg text, voicemail_message boolean, missed_call boolean, call_center_queue_uuid uuid,
		cc_side text, cc_member_uuid uuid, cc_queue_joined_epoch numeric, cc_queue text,
		cc_member_session_uuid uuid, cc_agent_uuid uuid, cc_agent text, cc_agent_type text,
		cc_agent_bridged text, cc_queue_answered_epoch numeric, cc_queue_terminated_epoch numeric,
		cc_queue_canceled_epoch numeric, cc_cancel_reason text, cc_cause text, waitsec numeric,
		conference_name text, conference_uuid uuid, conference_member_id text, digits_dialed text,
		pin_number text, status text, call_disposition text, hangup_cause text, hangup_cause_q850 numeric,
		sip_hangup_disposition text, ring_group_uuid uuid, ivr_menu_uuid uuid, call_flow jsonb, xml text,
		json jsonb, insert_date timestamptz, insert_user uuid, update_date timestamptz, update_user uuid
	)';

	private PDO $db;
	private ?string $loaded = null;

	private function __construct(string $dsn)
	{
		$this->db = new PDO($dsn);
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		// timestamps come back as the fixtures write them
		$this->db->exec("SET TIME ZONE 'UTC'");
		$this->db->exec(self::V_XML_CDR);
	}

	/** Connect and put it behind the fake database class. Null when REST_API_PGSQL_DSN isn't set. */
	public static function install(): ?self
	{
		$dsn = getenv(self::DSN_VARIABLE);
		if (!$dsn) {
			return null;
		}
		return \database::$pgsql = new self($dsn);
	}

	/** The connection, for tests that inspect the server. */
	public function pdo(): PDO
	{
		return $this->db;
	}

	/** Like FusionPBX 5.6.5's database::select(), which also disables server-side prepares. */
	public function select(string $sql, ?array $parameters, string $return_type)
	{
		$this->db->setAttribute(PDO::PGSQL_ATTR_DISABLE_PREPARES, true);
		return $this->run($sql, $parameters, $return_type);
	}

	/** Like FusionPBX 5.6.5's database::execute(). */
	public function execute(string $sql, ?array $parameters)
	{
		$this->db->setAttribute(PDO::PGSQL_ATTR_DISABLE_PREPARES, false);
		return $this->run($sql, $parameters, 'all');
	}

	// FusionPBX returns false on a database error. the error is kept in
	// "pgsql_errors" so a test can show it instead of a bare 500
	private function run(string $sql, ?array $parameters, string $return_type)
	{
		$this->load();
		FakeStore::update(function (&$state) use ($sql, $parameters) {
			$state['queries'][] = array('sql' => $sql, 'parameters' => $parameters);
		});
		try {
			$statement = $this->db->prepare($sql);
			$statement->execute(is_array($parameters) ? $parameters : array());
			switch ($return_type) {
				case 'row':
					return $statement->fetch(PDO::FETCH_ASSOC);
				case 'column':
					return $statement->fetchColumn();
				default:
					return $statement->fetchAll(PDO::FETCH_ASSOC);
			}
		} catch (PDOException $e) {
			FakeStore::update(function (&$state) use ($sql, $e) {
				$state['pgsql_errors'][] = $e->getMessage().' in: '.$sql;
			});
			return false;
		}
	}

	// copy the FakeStore rows of v_xml_cdr into the table, when they changed
	private function load(): void
	{
		$rows = FakeStore::read()['tables']['v_xml_cdr'] ?? array();
		$hash = md5(serialize($rows));
		if ($hash === $this->loaded) {
			return;
		}
		$this->db->exec('TRUNCATE v_xml_cdr');
		foreach ($rows as $row) {
			$columns = array_keys($row);
			$sql = 'INSERT INTO v_xml_cdr ('.implode(', ', $columns).') VALUES (:'.implode(', :', $columns).')';
			$this->db->prepare($sql)->execute($row);
		}
		$this->db->exec('ANALYZE v_xml_cdr');
		$this->loaded = $hash;
	}
}
