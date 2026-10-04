<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Application\Embedding;

use Bitrix\Rest\Api\Placement;
use Bitrix\Rest\Exceptions\ArgumentNullException;
use Bitrix\Rest\Exceptions\ArgumentTypeException;
use Bitrix\Rest\Internal\Entity\Application\App;
use CRestServer;
use ReflectionClass;

class EmbeddingUninstaller
{
	use EmbeddingLegacyTrait;

	/**
	 * @throws ArgumentTypeException
	 * @throws ArgumentNullException
	 */
	public function uninstall(
		App $app,
		string $placement,
		?string $handler = null,
		?int $targetUserId = null,
		int $userId = 0,
	): int
	{
		return $this->uninstallLegacy($app, $userId, $placement, $handler, $targetUserId);
	}

	/**
	 * @throws ArgumentNullException
	 * @throws ArgumentTypeException
	 */
	private function uninstallLegacy(
		App $app,
		int $userId,
		string $placement,
		?string $handler = null,
		?int $targetUserId = null,
	): int
	{
		$server = new CRestServer([]);
		$reflectedServer = new ReflectionClass($server);

		$this->setServerServiceDescription($server, $reflectedServer);
		$this->setServerAuthData($server, $reflectedServer, $app->getScope(), $userId);
		$this->setServerClientId($server, $reflectedServer, $app->getClientId());

		$params = array_filter(
			[
				'PLACEMENT' => $placement,
				'HANDLER' => $handler,
				'USER_ID' => $targetUserId,
			],
			static fn(mixed $item): bool => $item !== null,
		);

		return Placement::unbind($params, null, $server)['count'] ?? 0;
	}
}
