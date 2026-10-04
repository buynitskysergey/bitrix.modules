<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Document\Flipchart\Configuration;
use Bitrix\Disk\Folder;
use Bitrix\Disk\ProxyType;
use Bitrix\Disk\Storage;
use Bitrix\Main\ObjectException;

/**
 * Decides which board service instance serves an object, with three outcomes.
 *
 * null is a full outcome, not an error: it means no instance may be contacted. Returning old on an
 * anomaly would introduce a clean board's document_id to the old instance, and the stage 1 invariant
 * would be broken for it permanently.
 */
final class ServiceProfileResolver
{
	/** Storage classification: a group id, false for a valid non-group storage, null for an anomaly. */
	private const NON_GROUP_STORAGE = false;

	/**
	 * Corruption belongs to the option, not to an object, and a resolver is built per call site: only a
	 * per-process flag keeps a folder-wide walk over a hundred boards from writing a hundred equal lines.
	 */
	private static bool $corruptionReported = false;

	public function __construct(
		private readonly PilotProjectList $projects,
	)
	{
	}

	public static function createFromOptions(): self
	{
		return new self(Configuration::getPilotProjects());
	}

	public function resolveForObject(BaseObject $object): ?ServiceProfile
	{
		if ($this->projects->isEmpty())
		{
			return ServiceProfile::Old;
		}

		$realObject = $this->normalize($object);
		if ($realObject === null)
		{
			return null;
		}

		return $this->resolveByStorage($realObject->getStorage());
	}

	/**
	 * The same three outcomes for the entry points that open a board in a browser, the proxy and the
	 * addresses of the new instance included.
	 *
	 * The proxy can be switched on after a pilot is enabled. It signs every token whatever the profile
	 * is, so a token minted for the new profile would be a credential of the cloud handed to the new
	 * instance. The working paths of BoardService are closed by BoardApiServiceFactory, which refuses to
	 * build a client; opening a board builds no client at all, so its entry points decide here instead.
	 *
	 * Every way of opening a board fails the same way, so every way of failing is decided here: an entry
	 * point that only handled the unresolved outcome would let an unusable new profile through to the
	 * component, and the exception raised there reaches nobody but the log.
	 */
	public function resolveForEditor(?BaseObject $object): ?ServiceProfile
	{
		if ($object === null)
		{
			return null;
		}

		$profile = $this->resolveForObject($object);
		if ($profile !== ServiceProfile::New)
		{
			return $profile;
		}

		if (Configuration::isUsingDocumentProxy())
		{
			return null;
		}

		// Both addresses, not only the one the page prints: an unreadable api host lets a board open and
		// loses the drawing at the first save, which is worse than the refusal it is traded for.
		try
		{
			Configuration::getApiHost(ServiceProfile::New);
			Configuration::getAppUrl(ServiceProfile::New);
		}
		catch (ConfigurationException $exception)
		{
			PilotLog::error(
				'Board service address is not usable: {entryPoint}, object {objectId}, {reason}',
				[
					'entryPoint' => 'resolveForEditor',
					'objectId' => (int)$object->getId(),
					'reason' => $exception->getMessage(),
				],
			);

			return null;
		}

		return $profile;
	}

	public function resolveForDestination(Folder $folder): ?ServiceProfile
	{
		if ($this->projects->isEmpty())
		{
			return ServiceProfile::Old;
		}

		$realFolder = $this->normalize($folder);
		if (!$realFolder instanceof Folder)
		{
			return null;
		}

		return $this->resolveByStorage($realFolder->getStorage());
	}

	private function resolveByStorage(?Storage $storage): ?ServiceProfile
	{
		if ($storage === null)
		{
			return null;
		}

		$groupId = $this->classifyStorage($storage);
		if ($groupId === null)
		{
			return null;
		}

		if ($this->projects->isGloballyCorrupted())
		{
			if ($groupId === self::NON_GROUP_STORAGE)
			{
				return ServiceProfile::Old;
			}

			$this->reportCorruptedOptionOnce();

			return null;
		}

		if ($groupId === self::NON_GROUP_STORAGE)
		{
			return ServiceProfile::Old;
		}

		// No default branch on purpose: a state added later must fail to compile here rather than
		// fall through to Old, which is the fail-open this whole resolver exists to prevent.
		return match ($this->projects->getState($groupId))
		{
			PilotProjectState::Active => ServiceProfile::New,
			PilotProjectState::Pending, PilotProjectState::Blocked => null,
			null => ServiceProfile::Old,
		};
	}

	/**
	 * One corrupted token closes the boards of every group storage of the portal, and the refusals of
	 * the call sites name only an entry point and an object id: without this line the operator has no
	 * way to learn which token has to be fixed.
	 */
	private function reportCorruptedOptionOnce(): void
	{
		if (self::$corruptionReported)
		{
			return;
		}

		self::$corruptionReported = true;

		PilotLog::error(
			'Pilot project option is corrupted, boards of every group storage are closed: {tokens}',
			[
				'tokens' => implode(', ', $this->projects->getCorruptedTokens()),
			],
		);
	}

	/**
	 * A symlink has its own id and storage, so without normalisation a link to a pilot board would
	 * resolve to old while the file itself resolves to new: one file served by two instances.
	 */
	private function normalize(BaseObject $object): ?BaseObject
	{
		try
		{
			return $object->getRealObject();
		}
		catch (ObjectException)
		{
			return null;
		}
	}

	/**
	 * @return int|false|null group id, false for a valid non-group storage, null for an anomaly
	 */
	private function classifyStorage(Storage $storage): int|false|null
	{
		$entityId = (string)$storage->getEntityId();
		if ($entityId === '')
		{
			return null;
		}

		// getProxyType() lazily loads the storage's module and throws on an unusable entity type.
		// The class of a valid type is not declared before that load, so it cannot be checked earlier.
		try
		{
			$proxyType = $storage->getProxyType();
		}
		catch (\Throwable)
		{
			return null;
		}

		if (!$proxyType instanceof ProxyType\Base)
		{
			return null;
		}

		if (!$proxyType instanceof ProxyType\Group)
		{
			// The numeric requirement below applies to Group only, and this early return is what keeps it
			// there. A personal storage is addressed by a numeric user id, so hoisting that check above
			// this line would send a personal storage to New whenever a user id equals a pilot group id.
			return self::NON_GROUP_STORAGE;
		}

		// The same grammar as the option: a storage id and an option key are compared to each other,
		// so they must normalise identically or an active pilot silently resolves to Old.
		return PilotProjectList::parseGroupId($entityId);
	}
}
