<?php
namespace RestApi\Test\Http;

use RestApi\Test\Support\SessionIsolationTestCase;

/** A FusionPBX version that starts the session even when $no_session is set. */
class SessionIsolationWhenFusionPbxIgnoresNoSessionTest extends SessionIsolationTestCase
{
	protected static function sessionMode(): string
	{
		return 'ignored';
	}
}
