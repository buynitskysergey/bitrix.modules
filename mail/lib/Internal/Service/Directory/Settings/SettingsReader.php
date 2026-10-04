<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Directory\Settings;

use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Internal\Entity\Directory\DirectoriesSettings;
use Bitrix\Mail\Internal\Service\Directory\DirectorySerializer;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internals\Entity\MailboxDirectory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final readonly class SettingsReader
{
	public function __construct(
		private DirectorySerializer $serializer = new DirectorySerializer(),
	)
	{
	}

	/**
	 * @param GenerationScope|null $scope Folders of one prepared generation. Such folders are
	 *                                    already stored, and their source is not the one the
	 *                                    mailbox connects to, so no live listing is made.
	 * @return Result Result data on success: DirectoriesSettings instance
	 * @throws \Exception
	 */
	public function read(int $mailboxId, ?GenerationScope $scope = null): Result
	{
		$result = new Result();

		$mailboxHelper = Mailbox::createInstance($mailboxId, false);
		if (!$mailboxHelper)
		{
			$result->addError(new Error('Mailbox was not found', 'MAIL_CLIENT_MAILBOX_NOT_FOUND'));

			return $result;
		}

		$cacheFailed = $scope === null && $mailboxHelper->cacheDirs() === false;

		$dirHelper = new MailboxDirectoryHelper($mailboxId, null, $scope);
		$dirHelper->reloadDirs();

		if ($cacheFailed && empty($dirHelper->getDirs()))
		{
			foreach ($mailboxHelper->getErrors()->toArray() as $error)
			{
				$result->addError($error);
			}

			return $result;
		}

		$settings = new DirectoriesSettings(
			items: $this->serializer->serializeTree($dirHelper->buildTreeDirs()),
			outcome: $this->serializer->serializeAssigned($this->extractDir($dirHelper->getOutcome())),
			trash: $this->serializer->serializeAssigned($this->extractDir($dirHelper->getTrash())),
			spam: $this->serializer->serializeAssigned($this->extractDir($dirHelper->getSpam())),
			maxLevel: MailboxDirectoryHelper::getMaxLevelDirs(),
		);

		return $result->setData(['settings' => $settings]);
	}

	private function extractDir(mixed $dir): ?MailboxDirectory
	{
		return $dir instanceof MailboxDirectory ? $dir : null;
	}
}
