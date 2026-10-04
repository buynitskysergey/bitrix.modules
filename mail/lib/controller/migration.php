<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Integration\MailService\MigrationStatusProvider;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Intranet;

final class Migration extends Controller
{
	public const ERROR_STATUS_BATCH_TOO_LARGE = 'MAIL_MIGRATION_STATUS_BATCH_TOO_LARGE';

	private const STATUS_BATCH_LIMIT = 100;

	protected function getDefaultPreFilters(): array
	{
		return [
			new Main\Engine\ActionFilter\Authentication(),
			new Main\Engine\ActionFilter\HttpMethod([Main\Engine\ActionFilter\HttpMethod::METHOD_POST]),
			new Main\Engine\ActionFilter\Csrf(),
			new Intranet\ActionFilter\IntranetUser(),
		];
	}

	public function getStatusAction(int $mailboxId): ?array
	{
		if (!MailboxAccess::hasCurrentUserAnyAccessToMailbox($mailboxId))
		{
			$this->addError(new Error('Access to the mailbox is denied', 403));

			return null;
		}

		$mailboxExists = (bool)MailboxTable::getList([
			'select' => ['ID'],
			'filter' => ['=ID' => $mailboxId],
			'limit' => 1,
		])->fetch();
		if (!$mailboxExists)
		{
			$this->addError(new Error('The mailbox is not found', 404));

			return null;
		}

		$status = (new MigrationStatusProvider())->getLatest($mailboxId);

		return [
			'status' => $status?->toPublicArray() ?? [
				'mailboxId' => $mailboxId,
				'visibility' => 'hidden',
				'status' => null,
				'reason' => null,
			],
		];
	}

	/** @return array{statuses: array<int, array<string, mixed>>} */
	public function getStatusesAction(array $mailboxIds): array
	{
		$mailboxIds = array_values(array_unique(array_filter(array_map('intval', $mailboxIds))));
		if ($mailboxIds === [])
		{
			return ['statuses' => []];
		}
		if (count($mailboxIds) > self::STATUS_BATCH_LIMIT)
		{
			$this->addError(new Error(
				'Too many mailbox migration statuses were requested',
				self::ERROR_STATUS_BATCH_TOO_LARGE,
			));

			return ['statuses' => []];
		}

		$available = MailboxTable::getUserMailboxes((int)CurrentUser::get()->getId(), true);
		$availableIds = array_fill_keys(array_map('intval', array_keys($available)), true);
		$allowedIds = array_values(array_filter(
			$mailboxIds,
			static fn (int $mailboxId): bool => isset($availableIds[$mailboxId]),
		));
		$known = (new MigrationStatusProvider())->getLatestForMailboxes($allowedIds);
		$statuses = [];
		foreach ($allowedIds as $mailboxId)
		{
			$status = $known[$mailboxId] ?? null;
			$statuses[$mailboxId] = $status?->toPublicArray() ?? [
				'mailboxId' => $mailboxId,
				'visibility' => 'hidden',
				'status' => null,
				'reason' => null,
			];
		}

		return ['statuses' => $statuses];
	}
}
