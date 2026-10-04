<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Rest\File;

final class ScopedRestServer extends \CRestServer
{
	public function __construct(\CRestServer $source)
	{
		$this->auth = $source->getAuth();
		$this->authData = $source->getAuthData();
		$this->transport = $source->getTransport();
		$this->scope = 'crm';
	}
}
