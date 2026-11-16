<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Note\Internal\Service\ExternalAccess\ExternalAccessLifecycleService;
use Bitrix\Note\Public\Provider\ExternalAccessProvider;

/**
 * Stores the state of the public link of one knowledge base.
 *
 * A thin command per the write-path convention of the module: everything the operation decides -
 * issuing the token, hashing the password, telling "leave as is" from "clear" - lives in the
 * service. ACL stays in the controller.
 *
 * The answer is the state form of the provider, the same one the read side gives out: the
 * token and the password hash stay inside the module.
 *
 * $options is passed to the service as is, see ExternalAccessLifecycleService::save().
 */
final class SaveExternalAccessCommand extends AbstractCommand
{
	/**
	 * @param array{expiresAt?: Date|null, password?: string|null} $options
	 */
	public function __construct(
		private readonly int $collectionId,
		private readonly int $userId,
		private readonly bool $isEnabled,
		private readonly array $options = [],
		private readonly ExternalAccessLifecycleService $service = new ExternalAccessLifecycleService(),
		private readonly ExternalAccessProvider $provider = new ExternalAccessProvider(),
	) {}

	protected function execute(): Result
	{
		$this->service->save($this->collectionId, $this->userId, $this->isEnabled, $this->options);

		// The state form and not the stored record: the record carries the token and the password
		// hash, and a command is a public class - whoever calls it next would hand them out by
		// simply returning what it answered.
		return $this->createResult(['state' => $this->provider->getState($this->collectionId)]);
	}

	private function createResult(array $data = []): Result
	{
		$result = new Result();
		if (!empty($data))
		{
			$result->setData($data);
		}

		return $result;
	}
}
