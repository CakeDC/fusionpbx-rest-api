<?php
namespace RestApi\Test\Http;

use RestApi\Test\Support\SessionIsolationTestCase;

/** PHP configured with session.auto_start, so the session starts before rest.php runs. */
class SessionIsolationWithSessionAutoStartTest extends SessionIsolationTestCase
{
	protected static function phpSettings(): array
	{
		return array('session.auto_start' => '1');
	}
}
