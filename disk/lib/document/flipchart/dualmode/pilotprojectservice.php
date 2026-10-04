<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

use Bitrix\Disk\Document\Flipchart\Configuration;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Storage;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The only supported way to change the pilot project list.
 *
 * Editing the options by hand is an emergency workaround, not a tool: the procedure has
 * preconditions, a post-check and an order of writes, and a wrong order loses board content.
 *
 * There is no console command on purpose: it would be a thin wrapper over this class, would need
 * Symfony Console (optional on premise, and the command class does not load without it) and ssh access
 * to the server. The procedure is run from the developer console of the portal
 * (/bitrix/admin/php_command_line.php) or from anywhere else PHP can be executed:
 *
 *     $service = new PilotProjectService();
 *
 *     // 1. Prepare: checks the cloud proxy, the shadowing rows of b_option_site, the emptiness of the
 *     //    group storage and the readability of the addresses, then writes the project as pending.
 *     $result = $service->prepare(12, 'http://board-app:8080', 'http://localhost:8381/app');
 *
 *     // 2. Activate: repeats all four checks and turns the project to active.
 *     $result = $service->activate(12);
 *
 *     // Removing a project that was never activated:
 *     $result = $service->drop(12);
 *
 *     // Removing an active one: the last edits are lost, stage 1 has no safe rollback.
 *     $result = $service->emergencyRollback(12, acceptDataLoss: true);
 *
 *     echo $result->isSuccess() ? 'ok' : implode('; ', $result->getErrorMessages());
 *
 * Writing the flipchart.new_service_group_ids option by hand bypasses the emptiness precondition of the
 * group storage, one of the three supports of the stage 1 invariant: a pilot enabled over a project that
 * already holds boards sends them to the new instance while the old one knows them, and that cannot be
 * undone, since the old instance remembers a document_id forever.
 */
final class PilotProjectService
{
	public const LOCK_NAME = 'disk.flipchart.pilot';

	private const OPTION_LIST = 'flipchart.new_service_group_ids';
	private const OPTION_API_HOST = 'flipchart.new_service.api_host';
	private const OPTION_APP_URL = 'flipchart.new_service.app_url';

	public function __construct(
		private readonly PilotStorageInspector $inspector = new PilotStorageInspector(),
		private readonly ShadowingOptionInspector $shadowingInspector = new DefaultShadowingOptionInspector(),
		private readonly NamedLock $lock = new DefaultNamedLock(),
	)
	{
	}

	public function prepare(int $groupId, string $apiHost, string $appUrl): Result
	{
		return $this->withLock(function () use ($groupId, $apiHost, $appUrl): Result {
			$result = new Result();

			$list = Configuration::getPilotProjects();
			if (!$list->isParsedCompletely())
			{
				return $result->addError(
					new Error('Pilot project option is not parsed completely; fix it by hand first'),
				);
			}

			if ($groupId <= 0)
			{
				return $result->addError(new Error('Group id must be a positive integer'));
			}

			if ($list->getState($groupId) !== null)
			{
				return $result->addError(
					new Error("Group {$groupId} is already in the list; prepare is not idempotent"),
				);
			}

			$storage = $this->findGroupStorage($groupId);
			if ($storage === null)
			{
				return $result->addError(new Error("Group {$groupId} has no group storage"));
			}

			// JwtService::generateToken() signs on the proxy whatever the profile is, and the proxy
			// answers with the addresses of the old instance: the new profile cannot work here, and no
			// address of its own repairs that.
			if (Configuration::isUsingDocumentProxy())
			{
				return $result->addError(
					new Error('boards_use_documentproxy is Y; a pilot project cannot be enabled on this portal'),
				);
			}

			$shadowing = $this->findShadowingError();
			if ($shadowing !== null)
			{
				return $result->addError($shadowing);
			}

			$apiHost = $this->normalizeAddress($apiHost);
			$appUrl = $this->normalizeAddress($appUrl);
			if ($apiHost === '' || $appUrl === '')
			{
				return $result->addError(new Error('Both the api host and the app url are required'));
			}

			foreach (['api host' => $apiHost, 'app url' => $appUrl] as $name => $address)
			{
				if (!ServiceAddress::isValid($address))
				{
					return $result->addError(
						new Error("The {$name} must be an address of the form http(s)://host[:port][/path]"),
					);
				}
			}

			if (!$list->isEmpty())
			{
				$storedApiHost = $this->normalizeAddress((string)Option::get('disk', self::OPTION_API_HOST, ''));
				$storedAppUrl = $this->normalizeAddress((string)Option::get('disk', self::OPTION_APP_URL, ''));
				if ($storedApiHost !== $apiHost || $storedAppUrl !== $appUrl)
				{
					return $result->addError(new Error('Address pair is immutable while the list is not empty'));
				}
			}

			$boards = $this->inspector->findBoards((int)$storage->getId());
			if (!$boards->isEmpty())
			{
				return $result->addError(
					new Error('Group storage is not empty, board object ids: ' . $boards->describe()),
				);
			}

			// First write happens only here. Addresses go first: between the list and the addresses
			// the resolver would hand out the new profile with an empty address.
			if ($list->isEmpty())
			{
				Option::set('disk', self::OPTION_API_HOST, $apiHost);
				Option::set('disk', self::OPTION_APP_URL, $appUrl);
			}

			Option::set('disk', self::OPTION_LIST, $list->withState($groupId, PilotProjectState::Pending)->toRaw());

			return $result;
		});
	}

	public function activate(int $groupId): Result
	{
		return $this->withLock(function () use ($groupId): Result {
			$result = new Result();

			$list = Configuration::getPilotProjects();
			if (!$list->isParsedCompletely())
			{
				return $result->addError(
					new Error('Pilot project option is not parsed completely; fix it by hand first'),
				);
			}

			if ($list->getState($groupId) !== PilotProjectState::Pending)
			{
				return $result->addError(new Error("Group {$groupId} is not in the pending state"));
			}

			$storage = $this->findGroupStorage($groupId);
			if ($storage === null)
			{
				return $result->addError(new Error("Group {$groupId} has no group storage"));
			}

			// Activation is the transition after which the traffic really goes to the new instance, so the
			// checks of prepare are repeated here. Both of them can change between prepare and activate,
			// and neither leaves a trace in the answer of this command: the proxy turned on closes the
			// boards of the pilot at runtime, a shadowing row sends them back to the old instance.
			// Without the repetition the operator is told done over a project nobody serves.
			if (Configuration::isUsingDocumentProxy())
			{
				return $result->addError(
					new Error('boards_use_documentproxy is Y; a pilot project cannot be activated on this portal'),
				);
			}

			$shadowing = $this->findShadowingError();
			if ($shadowing !== null)
			{
				return $result->addError($shadowing);
			}

			// The address pair is written by this service alone, but so is the list, and activate repeats
			// the parse check of that list all the same: a hand edit between prepare and activate is the
			// case this whole repetition exists for. An address that no longer reads closes every board of
			// the project at runtime, which is again done reported over a project nobody serves.
			try
			{
				Configuration::getApiHost(ServiceProfile::New);
				Configuration::getAppUrl(ServiceProfile::New);
			}
			catch (ConfigurationException $exception)
			{
				return $result->addError(new Error($exception->getMessage()));
			}

			$boards = $this->inspector->findBoards((int)$storage->getId());
			if (!$boards->isEmpty())
			{
				return $result->addError(
					new Error('Post-check failed, group storage is not empty: ' . $boards->describe()),
				);
			}

			Option::set('disk', self::OPTION_LIST, $list->withState($groupId, PilotProjectState::Active)->toRaw());

			return $result;
		});
	}

	public function drop(int $groupId): Result
	{
		return $this->withLock(function () use ($groupId): Result {
			$result = new Result();

			// No proxy or address check on the way out, unlike prepare and activate: removing a record only
			// returns the project to the old instance, and an unreachable new instance is one of the
			// reasons to remove it. The shadowing check stays: a shadowing row makes the write report a lie.
			$shadowing = $this->findShadowingError();
			if ($shadowing !== null)
			{
				return $result->addError($shadowing);
			}

			$list = Configuration::getPilotProjects();
			if (!$list->isParsedCompletely())
			{
				return $result->addError(new Error('Pilot project option is not parsed completely; fix it by hand first'));
			}

			$state = $list->getState($groupId);
			if ($state === PilotProjectState::Active)
			{
				return $result->addError(
					new Error("Group {$groupId} is active; stage 1 has no safe rollback, use emergency-rollback"),
				);
			}

			if ($state !== PilotProjectState::Pending)
			{
				return $result->addError(new Error("Group {$groupId} has no pending record"));
			}

			Option::set('disk', self::OPTION_LIST, $list->withoutGroup($groupId)->toRaw());

			return $result;
		});
	}

	public function emergencyRollback(int $groupId, bool $acceptDataLoss): Result
	{
		return $this->withLock(function () use ($groupId, $acceptDataLoss): Result {
			$result = new Result();

			if (!$acceptDataLoss)
			{
				return $result->addError(new Error('Emergency rollback requires --accept-data-loss'));
			}

			// As in drop, neither the proxy nor the addresses stop the way out. The shadowing check does,
			// and it goes before the list is read: Option::get() prefers the site-scoped row, while
			// Option::set() rewrites the global one, so a rollback over a shadowing row would report
			// success and leave the traffic on the new instance.
			$shadowing = $this->findShadowingError();
			if ($shadowing !== null)
			{
				return $result->addError($shadowing);
			}

			$list = Configuration::getPilotProjects();
			if (!$list->isParsedCompletely())
			{
				return $result->addError(new Error('Pilot project option is not parsed completely; fix it by hand first'));
			}

			if ($list->getState($groupId) !== PilotProjectState::Active)
			{
				return $result->addError(new Error("Group {$groupId} is not active"));
			}

			Option::set('disk', self::OPTION_LIST, $list->withoutGroup($groupId)->toRaw());

			return $result;
		});
	}

	private function findShadowingError(): ?Error
	{
		$shadowing = $this->shadowingInspector->findShadowingSiteOptions();
		if ($shadowing === [])
		{
			return null;
		}

		return new Error(
			'b_option_site holds site-scoped rows that override the options this operation reads;'
			. ' delete them for every site and repeat: ' . implode(', ', $shadowing),
		);
	}

	/**
	 * The storage of a group is read on both ways into the pilot, and the answer of the driver is the
	 * portal's own answer to what the storage of this group is: the emptiness precondition is checked
	 * against it, so a substitute here would check a substitute.
	 */
	private function findGroupStorage(int $groupId): ?Storage
	{
		return Driver::getInstance()->getStorageByGroupId($groupId);
	}

	private function normalizeAddress(string $value): string
	{
		return ServiceAddress::normalize($value);
	}

	private function withLock(callable $operation): Result
	{
		if (!$this->lock->acquire(self::LOCK_NAME))
		{
			return (new Result())->addError(new Error('Another pilot operation is running'));
		}

		try
		{
			return $operation();
		}
		finally
		{
			$this->lock->release(self::LOCK_NAME);
		}
	}
}
