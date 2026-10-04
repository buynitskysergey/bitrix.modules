<?php

namespace Bitrix\Bizproc\Api\Service;

use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateVersion;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeEventType;
use Bitrix\Bizproc\Api\Enum\Template\TemplatePublicationType;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\GetVersionRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\GetVersionsRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\RecordPublicationRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\RestoreVersionRequest;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService\GetVersionResponse;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService\GetVersionsResponse;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService\RecordPublicationResponse;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService\RestoreVersionResponse;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\Internal\Config\TemplateHistory;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateChangeTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

class WorkflowTemplateHistoryService
{
	private const SNAPSHOT_FIELDS = ['TEMPLATE', 'PARAMETERS', 'VARIABLES', 'CONSTANTS', 'NAME', 'DESCRIPTION'];
	private const PARTICIPATION_FIELDS = ['TYPE', 'SYSTEM_CODE'];

	private TemplateAccessService $templateAccessService;

	public function __construct(?TemplateAccessService $templateAccessService = null)
	{
		$this->templateAccessService = $templateAccessService ?? new TemplateAccessService();
	}

	/**
	 * The journal is a surface of the node editor: a template of another type is edited elsewhere and a
	 * system one never opens at all, so neither takes part in the history — neither read nor written. An
	 * empty system code is a value of the column too and is checked exactly as the template loader checks it.
	 *
	 * @param array $templateRow template row carrying at least self::PARTICIPATION_FIELDS
	 */
	public function isTemplateRowInHistory(array $templateRow): bool
	{
		return $templateRow['SYSTEM_CODE'] === null
			&& $templateRow['TYPE'] === WorkflowTemplateType::Nodes->value
		;
	}

	/**
	 * Writes the published configuration of the template as a new version of its journal. Must be called
	 * inside the transaction of the publication itself: a version that cannot be written cancels it.
	 *
	 * The caller keeps the initial version of a template that has none: `ensureHistoryInitialized` has to
	 * run before the template row is overwritten, otherwise it copies the publication being made.
	 *
	 * The configuration is taken from the fields the caller has just written and only completed from the
	 * row, so the publication does not read its own body back.
	 */
	public function recordPublication(RecordPublicationRequest $request): RecordPublicationResponse
	{
		$response = new RecordPublicationResponse();

		$writtenFields = array_intersect_key($request->templateFields, array_flip(self::SNAPSHOT_FIELDS));
		$missingFields = array_values(array_diff(self::SNAPSHOT_FIELDS, array_keys($writtenFields)));

		$persisted = $this->getPersistedTemplate(
			$request->templateId,
			[...$missingFields, 'MODIFIED', ...self::PARTICIPATION_FIELDS],
		);
		if ($persisted === null)
		{
			$response->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError());

			return $response;
		}

		// The write is decided before the row is overwritten and confirmed on the row it left behind: a save
		// that turned the template into a type edited elsewhere takes it out of the history at the same time.
		if (!$this->isTemplateRowInHistory($persisted))
		{
			return $response;
		}

		$versionNumber = $this->getNextVersionNumber($request->templateId);

		try
		{
			WorkflowTemplateChangeTable::add([
				'TEMPLATE_ID' => $request->templateId,
				'EVENT_TYPE' => TemplateChangeEventType::Publication->value,
				'USER_ID' => $request->userId > 0 ? $request->userId : null,
				'CREATED' => new DateTime(),
				'VERSION_NUMBER' => $versionNumber,
				'PUBLICATION_TYPE' => $request->publicationType->value,
				'SNAPSHOT' => $this->buildSnapshot($writtenFields + $persisted),
				'TEMPLATE_MODIFIED' => $persisted['MODIFIED'],
			]);
		}
		catch (DuplicateEntryException)
		{
			$response->addError(ErrorMessage::TEMPLATE_VERSION_CONFLICT->getCodedError());

			return $response;
		}
		catch (SqlQueryException)
		{
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		// A template with fewer publications than the limit has nothing to rotate and pays no query for it.
		if ($versionNumber > TemplateHistory::getVersionLimit() && !$this->rotate($request->templateId))
		{
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		return $response->setVersionNumber($versionNumber);
	}

	/**
	 * Marks the birth of a template in its journal. A template created while the history was already on
	 * has no configuration older than the journal itself, so its history stays empty until the first
	 * publication instead of being backfilled from the live row.
	 *
	 * A template outside the history gets no mark at all: it may still be converted into a node one later,
	 * and a birth record would then pass its pre-feature configuration off as born with the journal.
	 */
	public function recordCreation(int $templateId, int $userId): bool
	{
		$persisted = $this->getPersistedTemplate($templateId, self::PARTICIPATION_FIELDS);
		if ($persisted === null || !$this->isTemplateRowInHistory($persisted))
		{
			return true;
		}

		try
		{
			$added = WorkflowTemplateChangeTable::add([
				'TEMPLATE_ID' => $templateId,
				'EVENT_TYPE' => TemplateChangeEventType::Creation->value,
				'USER_ID' => $userId > 0 ? $userId : null,
				'CREATED' => new DateTime(),
			]);
		}
		catch (SqlQueryException)
		{
			return false;
		}

		return $added->isSuccess();
	}

	public function getVersions(GetVersionsRequest $request): GetVersionsResponse
	{
		$response = new GetVersionsResponse();

		$accessError = $this->getAccessError($request->templateId, $request->userId);
		if ($accessError !== null)
		{
			$response->addError($accessError);

			return $response;
		}

		try
		{
			if (!$this->ensureHistoryInitialized($request->templateId))
			{
				$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

				return $response;
			}

			return $response->setVersions($this->getAvailableVersions($request->templateId));
		}
		catch (SqlQueryException)
		{
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}
	}

	/**
	 * The snapshot is handed over as it was published: a configuration made by the rules of its time is
	 * opened as is, without validation against the current ones.
	 */
	public function getVersion(GetVersionRequest $request): GetVersionResponse
	{
		$response = new GetVersionResponse();

		$accessError = $this->getAccessError($request->templateId, $request->userId);
		if ($accessError !== null)
		{
			$response->addError($accessError);

			return $response;
		}

		try
		{
			$version = null;
			foreach ($this->getAvailableVersions($request->templateId) as $available)
			{
				if ($available->id === $request->versionId)
				{
					$version = $available;

					break;
				}
			}

			if ($version === null)
			{
				$response->addError(ErrorMessage::TEMPLATE_VERSION_NOT_FOUND->getCodedError());

				return $response;
			}

			return $response
				->setVersion($version)
				->setTemplateData($this->getSnapshot($version->id))
			;
		}
		catch (SqlQueryException)
		{
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}
	}

	/**
	 * The configuration of the version becomes a new draft, the draft that was the freshest one stays
	 * untouched as a backup copy. The published template is not changed and no version is written.
	 */
	public function restoreVersion(RestoreVersionRequest $request): RestoreVersionResponse
	{
		$response = new RestoreVersionResponse();

		$versionResponse = $this->getVersion(
			new GetVersionRequest($request->templateId, $request->versionId, $request->userId),
		);
		if (!$versionResponse->isSuccess())
		{
			$response->addErrors($versionResponse->getErrors());

			return $response;
		}

		$template = $this->getPersistedTemplate(
			$request->templateId,
			['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE', 'TEMPLATE'],
		);
		if ($template === null)
		{
			$response->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError());

			return $response;
		}

		$backup = WorkflowTemplateDraftTable::getLatestDraftByTemplateId($request->templateId);

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$added = WorkflowTemplateDraftTable::add([
				'MODULE_ID' => $template['MODULE_ID'],
				'ENTITY' => $template['ENTITY'],
				'DOCUMENT_TYPE' => $template['DOCUMENT_TYPE'],
				'TEMPLATE_ID' => $request->templateId,
				'TEMPLATE_DATA' => $this->alignBlockMarks(
					$versionResponse->getTemplateData(),
					$template['TEMPLATE'] ?? [],
				),
				'STATUS' => WorkflowTemplateDraftTable::STATUS_RESTORED,
				'USER_ID' => $request->userId,
				'CREATED' => new DateTime(),
			]);

			$draftId = (int)$added->getId();

			if ($added->isSuccess())
			{
				$keptIds = $backup === null ? [$draftId] : [$draftId, (int)$backup['ID']];

				$this->deleteDraftsExcept($request->templateId, $keptIds);
			}
		}
		catch (\Throwable)
		{
			$this->rollbackTransaction($connection);
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		if (!$added->isSuccess())
		{
			$this->rollbackTransaction($connection);
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		$connection->commitTransaction();

		return $response->setDraftId($draftId);
	}

	/**
	 * The editor calls a block published while the mark of the graph it shows equals the mark of the live
	 * template. A version carries the marks of the publication it was made by, so every block of a restored
	 * draft would look unpublished; a block equal to the live one takes over the mark of that one instead and
	 * the canvas highlights the real difference. Marks themselves are not part of the comparison.
	 */
	private function alignBlockMarks(array $templateData, array $liveTemplate): array
	{
		$blocks = $templateData['TEMPLATE'][0][NodesToTemplate::ELEMENT_CHILDREN] ?? null;
		$liveBlocks = $this->indexBlocksById($liveTemplate);
		if (!is_array($blocks) || $liveBlocks === [])
		{
			return $templateData;
		}

		foreach ($blocks as $index => $block)
		{
			$id = $this->getBlockId($block);
			$liveBlock = $id === null ? null : ($liveBlocks[$id] ?? null);
			if ($liveBlock === null || $this->withoutMarks($block) != $this->withoutMarks($liveBlock))
			{
				continue;
			}

			$blocks[$index] = $this->applyPublishedMark($block, $liveBlock);
		}

		$templateData['TEMPLATE'][0][NodesToTemplate::ELEMENT_CHILDREN] = $blocks;

		return $templateData;
	}

	private function indexBlocksById(array $template): array
	{
		$indexed = [];
		foreach ($template[0][NodesToTemplate::ELEMENT_CHILDREN] ?? [] as $block)
		{
			$id = $this->getBlockId($block);
			if ($id !== null)
			{
				$indexed[$id] = $block;
			}
		}

		return $indexed;
	}

	private function getBlockId(mixed $block): ?string
	{
		$id = is_array($block) ? ($block['Node']['id'] ?? null) : null;

		return is_string($id) || is_int($id) ? (string)$id : null;
	}

	private function withoutMarks(array $block): array
	{
		unset($block['Node']['node']['updated'], $block['Node']['node']['published']);

		return $block;
	}

	private function applyPublishedMark(array $block, array $liveBlock): array
	{
		$publishedMark = $liveBlock['Node']['node']['published'] ?? null;
		if ($publishedMark === null)
		{
			unset($block['Node']['node']['updated']);
		}
		else
		{
			$block['Node']['node']['updated'] = $publishedMark;
		}

		return $block;
	}

	/**
	 * Drops the draft created by the restore, so the editor returns to the backup copy or, when there is
	 * none, to the published configuration.
	 */
	public function undoRestore(int $templateId, ?int $userId): RestoreVersionResponse
	{
		$response = new RestoreVersionResponse();

		$accessError = $this->getAccessError($templateId, $userId);
		if ($accessError !== null)
		{
			$response->addError($accessError);

			return $response;
		}

		$restored = $this->getRestoredDraft($templateId);
		if ($restored === null)
		{
			$response->addError(ErrorMessage::TEMPLATE_RESTORE_UNDO_UNAVAILABLE->getCodedError());

			return $response;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$deleted = WorkflowTemplateDraftTable::delete((int)$restored['ID']);
		}
		catch (\Throwable)
		{
			$this->rollbackTransaction($connection);
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		if (!$deleted->isSuccess())
		{
			$this->rollbackTransaction($connection);
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		$connection->commitTransaction();

		$remaining = WorkflowTemplateDraftTable::getLatestDraftByTemplateId($templateId);

		return $response->setDraftId($remaining === null ? null : (int)$remaining['ID']);
	}

	/**
	 * The user keeps the restored draft: the backup copy is not needed anymore.
	 */
	public function discardRestoreBackup(int $templateId, ?int $userId): RestoreVersionResponse
	{
		$response = new RestoreVersionResponse();

		$accessError = $this->getAccessError($templateId, $userId);
		if ($accessError !== null)
		{
			$response->addError($accessError);

			return $response;
		}

		$restored = $this->getRestoredDraft($templateId);
		if ($restored === null)
		{
			return $response;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->deleteDraftsExcept($templateId, [(int)$restored['ID']]);
			// The kept draft stops being a restore: with the backup gone there is nothing to undo,
			// so the editor must not offer the undo after a reload either.
			$updated = WorkflowTemplateDraftTable::update(
				(int)$restored['ID'],
				['STATUS' => WorkflowTemplateDraftTable::STATUS_RESTORED_WITHOUT_BACKUP],
			);
		}
		catch (\Throwable)
		{
			$this->rollbackTransaction($connection);
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		if (!$updated->isSuccess())
		{
			$this->rollbackTransaction($connection);
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		$connection->commitTransaction();

		return $response;
	}

	private function getRestoredDraft(int $templateId): ?array
	{
		$latest = WorkflowTemplateDraftTable::getLatestDraftByTemplateId($templateId);

		return WorkflowTemplateDraftTable::isRestoredDraft($latest) ? $latest : null;
	}

	/**
	 * @param int[] $keptIds never empty: the draft to keep is the reason the deletion happens at all
	 */
	private function deleteDraftsExcept(int $templateId, array $keptIds): void
	{
		WorkflowTemplateDraftTable::deleteByFilter([
			'=TEMPLATE_ID' => $templateId,
			'!@ID' => $keptIds,
		]);
	}

	private function rollbackTransaction(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
			{
				throw $exception;
			}
		}
	}

	/**
	 * Templates published before the feature was enabled get their first version on the first request that
	 * touches the history. The initial version only copies the live configuration and the publication
	 * metadata that survived on the template row.
	 */
	public function ensureHistoryInitialized(int $templateId): bool
	{
		$existingTypes = [];
		foreach ($this->getRecordedEvents($templateId) as $row)
		{
			if ((int)$row['EVENT_TYPE'] === TemplateChangeEventType::Creation->value)
			{
				return true;
			}

			$existingTypes[] = (int)$row['PUBLICATION_TYPE'];
		}

		foreach ($this->getActivePublicationTypes() as $type)
		{
			if (in_array($type->value, $existingTypes, true))
			{
				continue;
			}

			$persisted = $this->getPersistedTemplate($templateId);
			if ($persisted === null || \CBPHelper::isEmptyValue($persisted['TEMPLATE']))
			{
				continue;
			}

			$authorId = (int)($persisted['USER_ID'] ?? 0);

			try
			{
				$added = WorkflowTemplateChangeTable::add([
					'TEMPLATE_ID' => $templateId,
					'EVENT_TYPE' => TemplateChangeEventType::Publication->value,
					'USER_ID' => $authorId > 0 ? $authorId : null,
					'CREATED' => $persisted['MODIFIED'] ?? new DateTime(),
					'VERSION_NUMBER' => $this->getNextVersionNumber($templateId),
					'PUBLICATION_TYPE' => $type->value,
					'SNAPSHOT' => $this->buildSnapshot($persisted),
					'TEMPLATE_MODIFIED' => $persisted['MODIFIED'],
				]);
			}
			// A version number taken by a publication running in parallel means that publication has already
			// given the type its version: the history is initialized, just not by this call.
			catch (DuplicateEntryException)
			{
				continue;
			}
			catch (SqlQueryException)
			{
				return false;
			}

			if (!$added->isSuccess())
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return TemplatePublicationType[]
	 */
	private function getActivePublicationTypes(): array
	{
		return [TemplatePublicationType::Common];
	}

	/**
	 * Every kind of event the journal of the template already keeps: what it says about the birth of the
	 * template and about the publication types it holds is decided on the same rows.
	 */
	protected function getRecordedEvents(int $templateId): array
	{
		return WorkflowTemplateChangeTable::query()
			->setSelect(['EVENT_TYPE', 'PUBLICATION_TYPE'])
			->where('TEMPLATE_ID', $templateId)
			->setDistinct()
			->fetchAll()
		;
	}

	/**
	 * @param bool $loadAll whether to load every restorable version for rotation
	 * @return TemplateVersion[] freshest first; versions whose body was dropped by rotation are not versions
	 * anymore and never appear here
	 */
	protected function getAvailableVersions(int $templateId, bool $loadAll = false): array
	{
		$query = WorkflowTemplateChangeTable::query()
			->setSelect([
				'ID',
				'VERSION_NUMBER',
				'PUBLICATION_TYPE',
				'CREATED',
				'USER_ID',
				'TEMPLATE_MODIFIED',
				'LIVE_MODIFIED' => 'TEMPLATE.MODIFIED',
			])
			->where('TEMPLATE_ID', $templateId)
			->where('EVENT_TYPE', TemplateChangeEventType::Publication->value)
			->whereNotNull('SNAPSHOT')
			->setOrder(['VERSION_NUMBER' => 'DESC'])
		;
		if (!$loadAll)
		{
			$query->setLimit($this->getKeptVersionCount());
		}

		$rows = $query->fetchAll();

		$currentIds = [];
		foreach ($rows as $row)
		{
			if ($this->isVersionOfLiveTemplate($row))
			{
				$currentIds[(int)$row['PUBLICATION_TYPE']] ??= (int)$row['ID'];
			}
		}

		$versions = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$authorId = (int)$row['USER_ID'];

			$versions[] = new TemplateVersion(
				id: $id,
				versionNumber: (int)$row['VERSION_NUMBER'],
				publicationType: (int)$row['PUBLICATION_TYPE'],
				createdTimestamp: $row['CREATED'] instanceof DateTime ? $row['CREATED']->getTimestamp() : null,
				authorId: $authorId > 0 ? $authorId : null,
				isCurrent: in_array($id, $currentIds, true),
			);
		}

		return $versions;
	}

	/**
	 * The current version is the one the template row still carries: writes that go around the publication
	 * path (import, package installation) leave the journal behind, and then no version of it is current.
	 */
	private function isVersionOfLiveTemplate(array $row): bool
	{
		return $row['TEMPLATE_MODIFIED'] instanceof DateTime
			&& $row['LIVE_MODIFIED'] instanceof DateTime
			&& $row['TEMPLATE_MODIFIED']->getTimestamp() === $row['LIVE_MODIFIED']->getTimestamp()
		;
	}

	protected function getSnapshot(int $versionId): array
	{
		$row = WorkflowTemplateChangeTable::query()
			->setSelect(['SNAPSHOT'])
			->where('ID', $versionId)
			->fetch()
		;

		return is_array($row['SNAPSHOT'] ?? null) ? $row['SNAPSHOT'] : [];
	}

	/**
	 * A missing template and a foreign one are indistinguishable from the outside: both answer ACCESS_DENIED.
	 * The journal is a surface of the node editor, so a system template and a non-Nodes one are refused the
	 * same way: they live outside the template ACL and never read the draft a restore writes.
	 */
	private function getAccessError(int $templateId, ?int $userId): ?Error
	{
		if (!TemplateHistory::isEnabled())
		{
			return ErrorMessage::FEATURE_DISABLED->getCodedError();
		}

		$template = $this->getPersistedTemplate($templateId, self::PARTICIPATION_FIELDS);

		$isAllowed =
			$template !== null
			&& $this->isTemplateRowInHistory($template)
			&& $this->templateAccessService->canEdit($templateId, $userId)
		;

		return $isAllowed ? null : ErrorMessage::ACCESS_DENIED->getCodedError();
	}

	/**
	 * The configuration of the template in the order the journal keeps it: what the caller has just written
	 * is taken from it, the rest from the template row.
	 */
	private function buildSnapshot(array $fields): array
	{
		$snapshot = [];
		foreach (self::SNAPSHOT_FIELDS as $field)
		{
			$snapshot[$field] = $fields[$field] ?? null;
		}

		return $snapshot;
	}

	/**
	 * Rotation never keeps more restorable versions than this: the configured limit, and never fewer than
	 * one version per publication type, because the version currently live is never emptied.
	 */
	private function getKeptVersionCount(): int
	{
		return max(TemplateHistory::getVersionLimit(), count($this->getActivePublicationTypes()));
	}

	/**
	 * Keeps the configured number of restorable versions: the body of the oldest versions above the limit
	 * is dropped, while the row itself stays in the journal as an event. The freshest version of every
	 * publication type is the one currently active and is never emptied.
	 *
	 * @return bool false when the journal refused the rotation: the publication it belongs to is cancelled
	 * rather than reported with the message of the driver.
	 */
	private function rotate(int $templateId): bool
	{
		try
		{
			$available = $this->getAvailableVersions($templateId, loadAll: true);
			$excess = count($available) - TemplateHistory::getVersionLimit();

			foreach (array_reverse($available) as $version)
			{
				if ($excess <= 0)
				{
					break;
				}

				if ($version->isCurrent)
				{
					continue;
				}

				WorkflowTemplateChangeTable::update($version->id, ['SNAPSHOT' => null]);
				--$excess;
			}
		}
		catch (SqlQueryException)
		{
			return false;
		}

		return true;
	}

	private function getPersistedTemplate(int $templateId, ?array $select = null): ?array
	{
		$row = WorkflowTemplateTable::query()
			->setSelect($select ?? [...self::SNAPSHOT_FIELDS, 'MODIFIED', 'USER_ID'])
			->where('ID', $templateId)
			->fetch()
		;

		return $row ?: null;
	}

	private function getNextVersionNumber(int $templateId): int
	{
		$row = WorkflowTemplateChangeTable::query()
			->addSelect(Query::expr('MAX_VERSION')->max('VERSION_NUMBER'))
			->where('TEMPLATE_ID', $templateId)
			->fetch()
		;

		return (int)($row['MAX_VERSION'] ?? 0) + 1;
	}
}
