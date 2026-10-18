<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Setup;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Service\Collection\CollectionService;
use Bitrix\Note\Internal\Service\Document\DocumentService;

/**
 * Bootstraps a single welcome collection with one welcome document.
 * Called only on fresh module install (when DB schema has just been created),
 * never from updater. Authored by the system sentinel user (SystemUser::ID).
 *
 * Failures are logged to CEventLog but never propagated — module install
 * must not break because of welcome-content seeding.
 */
final class WelcomeContentInstaller
{
	public function __construct(
		private readonly CollectionService $collectionService = new CollectionService(),
		private readonly DocumentService $documentService = new DocumentService(),
	)
	{
	}

	public function install(): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$collectionName = (string)Loc::getMessage('NOTE_WELCOME_COLLECTION_NAME');
			$documentTitle = (string)Loc::getMessage('NOTE_WELCOME_DOC_TITLE');

			$collectionId = $this->collectionService->createAsSystem($collectionName);
			if ($collectionId === null)
			{
				$connection->rollbackTransaction();
				self::logFailure('Failed to create welcome collection.');

				return;
			}

			$policyResult = CollectionAccessService::installSystemPolicy(
				$collectionId,
				CollectionAccessService::LEVEL_MODERATE,
			);
			if (!$policyResult->isSuccess())
			{
				$connection->rollbackTransaction();
				self::logFailure(sprintf(
					'Failed to install welcome collection policy: %s',
					implode(', ', $policyResult->getErrorMessages()),
				));

				return;
			}

			$documentId = $this->documentService->createAsSystem(
				$collectionId,
				null,
				$documentTitle,
				$this->buildMarkdown(),
				DocumentTable::CONTENT_FORMAT_MD,
			);
			if ($documentId === null)
			{
				$connection->rollbackTransaction();
				self::logFailure('Failed to create welcome document.');

				return;
			}

			$connection->commitTransaction();

			Option::set('note', 'welcome_collection_id', (string)$collectionId);
			Option::set('note', 'welcome_document_id', (string)$documentId);
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			self::logFailure($exception->getMessage());
		}
	}

	private function buildMarkdown(): string
	{
		return (string)Loc::getMessage('NOTE_WELCOME_DOC_MARKDOWN_MSGVER_1');
	}

	private static function logFailure(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_WARNING,
			'AUDIT_TYPE_ID' => 'NOTE_INSTALL_WELCOME_FAIL',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}
}
