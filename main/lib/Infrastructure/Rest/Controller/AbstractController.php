<?php

namespace Bitrix\Main\Infrastructure\Rest\Controller;

use Bitrix\Rest\V3\Controller\RestController;

abstract class AbstractController extends RestController
{
	protected function getRequiredServer(): \CRestServer
	{
		$server = $this->getServer();

		if (!$server instanceof \CRestServer)
		{
			throw new \RuntimeException('REST server is not initialized.');
		}

		return $server;
	}
}
