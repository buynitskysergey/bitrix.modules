<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Date;
use Bitrix\Note\Infrastructure\Controller\ActionFilter;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Public\Command\DiscardExternalAccessCommand;
use Bitrix\Note\Public\Command\SaveExternalAccessCommand;
use Bitrix\Note\Public\Provider\CollectionProvider;
use Bitrix\Note\Public\Provider\ExternalAccessProvider;

/**
 * The three actions of the moderator over the public link of a knowledge base.
 *
 * This is the ordinary circuit of the module with the ordinary set of pre-filters - authentication
 * included. The public circuit of the visitor is a separate controller tree; nothing here is
 * relaxed for it.
 *
 * Every action is idempotent by the knowledge base: a repeated call with the same values answers
 * the same and produces no second record.
 */
class ExternalAccessController extends Controller
{
	/**
	 * Tells "the field was not sent" from "the field was sent as null": the first keeps the stored
	 * value, the second clears it.
	 */
	private const PARAM_KEEP = "\0keep";

	public function __construct(
		?HttpRequest $request = null,
		private readonly ExternalAccessProvider $provider = new ExternalAccessProvider(),
		private readonly CollectionProvider $collectionProvider = new CollectionProvider(),
	)
	{
		parent::__construct($request);
	}

	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new ActionFilter\NoteAccess(),
			],
		);
	}

	public function getAction(int $collectionId): ?array
	{
		if (!$this->assertModerateAccess($collectionId))
		{
			return null;
		}

		return $this->provider->getState($collectionId);
	}

	/**
	 * @param string|null $expiresAt 'YYYY-MM-DD'; not sent - the term is kept, null - dropped
	 * @param string|null $password not sent - kept, empty or null - dropped, a string - set
	 */
	public function saveAction(
		int $collectionId,
		bool $isEnabled,
		?string $expiresAt = self::PARAM_KEEP,
		?string $password = self::PARAM_KEEP,
	): ?array
	{
		if (!$this->assertModerateAccess($collectionId))
		{
			return null;
		}

		$options = [];

		if ($expiresAt !== self::PARAM_KEEP)
		{
			if ($expiresAt === null || $expiresAt === '')
			{
				// An empty string arrives from a form field the moderator cleared.
				$options['expiresAt'] = null;
			}
			else
			{
				$date = $this->parseLastDay($expiresAt);
				if ($date === null)
				{
					$this->addError(new Error(
						Loc::getMessage('NOTE_EXTERNAL_ACCESS_CONTROLLER_INVALID_EXPIRES'),
						ErrorCodeDictionary::EXTERNAL_ACCESS_INVALID_EXPIRES,
					));

					return null;
				}

				$options['expiresAt'] = $date;
			}
		}

		if ($password !== self::PARAM_KEEP)
		{
			// Whatever is left in the field is what gets stored, an empty field included: the empty
			// string is the only value of a password field that reaches the server intact, so it is
			// what carries the drop.
			$options['password'] = $password === '' ? null : $password;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		(new SaveExternalAccessCommand($collectionId, $userId, $isEnabled, $options))->run();

		return $this->provider->getState($collectionId);
	}

	public function discardAction(int $collectionId): ?bool
	{
		if (!$this->assertModerateAccess($collectionId))
		{
			return null;
		}

		$result = (new DiscardExternalAccessCommand($collectionId))->run();

		return (bool)($result->getData()['success'] ?? false);
	}

	/**
	 * The right is the moderator of the knowledge base, and a missing knowledge base answers the
	 * same code as a missing right: whether the base exists is not something an outsider learns
	 * from the answer.
	 */
	private function assertModerateAccess(int $collectionId): bool
	{
		$hasAccess = $collectionId > 0
			&& $this->collectionProvider->getById($collectionId) !== null
			&& CollectionAccessService::currentUserHasLevel($collectionId, CollectionAccessService::LEVEL_MODERATE)
		;

		if ($hasAccess)
		{
			return true;
		}

		$this->addError(new Error(
			Loc::getMessage('NOTE_EXTERNAL_ACCESS_CONTROLLER_FORBIDDEN'),
			ErrorCodeDictionary::EXTERNAL_ACCESS_FORBIDDEN,
		));

		return false;
	}

	private function parseLastDay(string $value): ?Date
	{
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1)
		{
			return null;
		}

		if (!checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]))
		{
			return null;
		}

		$date = new Date($value, 'Y-m-d');
		if ($date->getTimestamp() < (new Date())->getTimestamp())
		{
			return null;
		}

		return $date;
	}
}
