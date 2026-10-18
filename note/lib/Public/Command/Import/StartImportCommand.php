<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command\Import;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Note\Infrastructure\Agent\Import\ImportAgent;
use Bitrix\Note\Internal\Repository\ImportMapRepository;
use Bitrix\Note\Internal\Repository\ImportSessionRepository;
use Bitrix\Note\Internal\Service\Import\Source\LandingSource;
use Bitrix\Note\Internal\Service\Import\Source\OutlineSource;
use Bitrix\Note\Internal\Service\Import\Source\SourceInterface;
use Bitrix\Note\Internal\Service\Import\Source\WikiSource;
use Bitrix\Note\Internal\Service\Import\SourceReadableSnapshot;
use Bitrix\Note\Internal\Service\Import\Step\CreateCollectionStep;

class StartImportCommand extends AbstractCommand
{
	// The one source whose bases carry access of their own, and the branch the rule of an update
	// stands on. Which sources record a snapshot of what the initiator may read is stated once, by
	// {@see SourceReadableSnapshot}.
	private const RIGHTS_BEARING_SOURCE_TYPE = 'landing';

	private string $sourceType;
	private string $sourceUrl;
	private string $sourceToken;
	private array $collectionIds;
	private int $userId;
	private bool $overwrite;
	private ImportSessionRepository $sessionRepository;
	/** @var array{0: array<string,string>, 1: array<string,string>}|null taken once, {@see collectionSnapshot} */
	private ?array $collectionSnapshot = null;

	public function __construct(
		string $sourceType,
		string $sourceUrl = '',
		string $sourceToken = '',
		array $collectionIds = [],
		int $userId = 0,
		bool $overwrite = false,
		?ImportSessionRepository $sessionRepository = null,
	)
	{
		$this->sourceType = $sourceType;
		$this->sourceUrl = $sourceUrl;
		$this->sourceToken = $sourceToken;
		$this->collectionIds = $collectionIds;
		$this->userId = $userId;
		$this->overwrite = $overwrite;
		$this->sessionRepository = $sessionRepository ?? new ImportSessionRepository();
	}

	protected function execute(): Result
	{
		$result = new Result();

		if (empty($this->collectionIds))
		{
			$result->addError(new Error('No collections selected'));

			return $result;
		}

		// [P6.T4] The first gate of the two, and it stands before the one below on purpose: a refusal
		// over the right to update names a collection of note an earlier conversion of the base ended
		// up in, and that already tells the caller the base exists and was converted. About a base the
		// initiator may not read even that much is not said.
		if ($this->collectionsBeyondSourceReadAccess() !== [])
		{
			$result->addError(new Error(
				Loc::getMessage('NOTE_IMPORT_SOURCE_FORBIDDEN'),
				'IMPORT_SOURCE_FORBIDDEN',
			));

			return $result;
		}

		// API-02: an update the initiator has no right to is refused before anything is created, so
		// they are told why instead of finding a second base of the same name afterwards. The screen
		// already knows the answer (API-01) - this is the same answer given to a request that did not
		// come from it.
		if ($this->collectionsBeyondUpdateRight() !== [])
		{
			$result->addError(new Error(
				Loc::getMessage('NOTE_IMPORT_COLLECTION_FORBIDDEN'),
				'IMPORT_COLLECTION_FORBIDDEN',
			));

			return $result;
		}

		$existing = $this->sessionRepository->getByUser($this->userId);
		if ($existing !== null)
		{
			if ($existing['STATUS'] === 'in_progress')
			{
				$result->addError(new Error('Import already in progress', 'IMPORT_IN_PROGRESS'));

				return $result;
			}

			$oldSessionId = (int)$existing['ID'];
			Option::delete('main.stepper.note', ['name' => ImportAgent::class . "({$oldSessionId})"]);
			$this->sessionRepository->delete($oldSessionId);
		}

		$saveResult = $this->sessionRepository->save($this->userId, 'connecting');
		if (!$saveResult->isSuccess())
		{
			$result->addErrors($saveResult->getErrors());

			return $result;
		}

		$sessionId = (int)$saveResult->getData()['id'];

		try
		{
			// Snapshot collection names/urlIds in the user request context where
			// Rights::hasAccessForSite still sees the initiating user. The stepper
			// runs in an agent context where CurrentUser may differ, which would
			// make LandingSource::getCollections() filter every site out and force
			// CreateCollectionStep to fall back to the externalId (CODE) as name.
			[$collectionNames, $collectionUrlIds] = $this->collectionSnapshot();

			$initialOption = [
				'sourceType' => $this->sourceType,
				'sourceUrl' => $this->sourceUrl,
				'sourceToken' => $this->sourceToken,
				'userId' => $this->userId,
				'collectionIds' => $this->collectionIds,
				'collectionNames' => $collectionNames,
				'collectionUrlIds' => $collectionUrlIds,
				'totalItems' => 0,
				'doneCount' => 0,
				'errorCount' => 0,
				'errorMessage' => '',
				'collectionIndex' => 0,
				'step' => 'createCollection',
				'resultCollectionId' => null,
				'importedDocIds' => [],
				'attachmentDocIndex' => 0,
				'attachmentFileIndex' => 0,
				'totalAttachments' => 0,
				'doneAttachments' => 0,
				'overwrite' => $this->overwrite,
			];

			if (SourceReadableSnapshot::isRecordedFor($this->sourceType))
			{
				// ALG-03 / [P6.T4]: which bases the initiator may read is visible in this request and
				// nowhere else, so it is recorded here and the steps check themselves against it. The
				// listing above is that very answer - the source returns the bases the initiator can
				// read - so no second question is put to landing.
				$initialOption[SourceReadableSnapshot::OPTION_KEY] = SourceReadableSnapshot::record(
					array_keys($collectionNames),
				);
			}

			Option::set(
				'main.stepper.note',
				ImportAgent::class . "({$sessionId})",
				serialize($initialOption),
			);

			ImportAgent::bind(1, [$sessionId]);
			$this->sessionRepository->updateStatus($sessionId, 'in_progress');
		}
		catch (\Throwable $e)
		{
			Option::delete('main.stepper.note', ['name' => ImportAgent::class . "({$sessionId})"]);
			$this->sessionRepository->delete($sessionId);

			throw $e;
		}

		$result->setData([
			'sessionId' => $sessionId,
		]);

		return $result;
	}

	/**
	 * The selected bases the initiator may not read at the source ([P6.T4]). landing shows a base to
	 * whoever may read it and to nobody else, so the listing this command builds its snapshot from is
	 * that very answer, and a base missing from it is a base of somebody else - the conversion of it
	 * is refused before a session exists, rather than started and broken off in the background.
	 *
	 * The listing is taken for the initiator the conversion is started for. Through the screen that is
	 * whoever is logged in, but this command is public and another module can name any initiator it
	 * likes - {@see LandingSource} is built for that one, so the answer is about them (ALG-03).
	 *
	 * @return string[] external ids of the bases, empty when every selected base was readable
	 */
	private function collectionsBeyondSourceReadAccess(): array
	{
		if (!SourceReadableSnapshot::isRecordedFor($this->sourceType))
		{
			return [];
		}

		[$collectionNames] = $this->collectionSnapshot();
		$readable = SourceReadableSnapshot::record(array_keys($collectionNames));

		return array_values(array_diff(
			array_map('strval', $this->collectionIds),
			$readable,
		));
	}

	/**
	 * The listing of the source, taken once: the refusal above and the state of the conversion are
	 * two readings of one answer, and a source asked twice can answer twice.
	 *
	 * @return array{0: array<string,string>, 1: array<string,string>}
	 */
	private function collectionSnapshot(): array
	{
		return $this->collectionSnapshot ??= $this->snapshotCollections();
	}

	/**
	 * The selected bases whose earlier conversion the initiator may not update, by the very rules the
	 * step goes by ({@see CreateCollectionStep::countsAsEarlierConversion} for which base an update is
	 * even aimed at, {@see CreateCollectionStep::mayInitiatorUpdate} for the right to it) - they are
	 * asked in one place so that what is refused here is exactly what the step would refuse.
	 *
	 * The right is read for the initiator the conversion is being started for and not for whoever is
	 * logged in (ALG-03). Through the screen the two are the same person, but this command is public
	 * and another module can name any initiator it likes - the answer has to be about that one.
	 *
	 * @return string[] external ids of the bases, empty when the update is open to the initiator
	 */
	private function collectionsBeyondUpdateRight(): array
	{
		// The rule stands on the landing branch alone, and only an update can run into it.
		if (!$this->overwrite || $this->sourceType !== self::RIGHTS_BEARING_SOURCE_TYPE)
		{
			return [];
		}

		$mapRepository = new ImportMapRepository();
		$forbidden = [];
		foreach ($this->collectionIds as $collectionId)
		{
			$externalId = (string)$collectionId;
			$mappedCollectionId = $mapRepository->findCollectionId(
				$this->sourceType,
				CreateCollectionStep::collectionMapKey($this->sourceType, $externalId),
			);
			if ($mappedCollectionId === null)
			{
				continue;
			}

			// A base the screen never named is not one to refuse over: an archived collection is left out
			// of the answer about earlier conversions (API-01), so the conversion makes a base of its own
			// - the very thing the step does with it.
			if (!CreateCollectionStep::countsAsEarlierConversion($this->sourceType, $mappedCollectionId))
			{
				continue;
			}

			if (!CreateCollectionStep::mayInitiatorUpdate($this->sourceType, $mappedCollectionId, $this->userId))
			{
				$forbidden[] = $externalId;
			}
		}

		return $forbidden;
	}

	/**
	 * @return array{0: array<string,string>, 1: array<string,string>}
	 */
	private function snapshotCollections(): array
	{
		$source = $this->createSource();
		if ($source === null)
		{
			return [[], []];
		}

		try
		{
			$result = $source->getCollections();
		}
		catch (\Throwable)
		{
			return [[], []];
		}

		$names = [];
		$urlIds = [];
		foreach ($result->data['collections'] ?? [] as $c)
		{
			if (!isset($c['id']))
			{
				continue;
			}

			$id = (string)$c['id'];
			$names[$id] = (string)($c['name'] ?? $id);
			if (!empty($c['urlId']))
			{
				$urlIds[$id] = (string)$c['urlId'];
			}
		}

		return [$names, $urlIds];
	}

	private function createSource(): ?SourceInterface
	{
		return match ($this->sourceType)
		{
			'outline' => new OutlineSource($this->sourceUrl, $this->sourceToken),
			'landing' => new LandingSource($this->userId),
			'wiki' => new WikiSource($this->userId),
			default => null,
		};
	}
}
