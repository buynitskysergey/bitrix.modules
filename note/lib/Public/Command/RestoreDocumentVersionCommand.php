<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Internal\Repository\DocumentVersionRepository;

/**
 * Restores a document's content to a past version's snapshot. Reuses the overwrite
 * path (OverwriteDocumentContentCommand) so the version/content_changed bookkeeping
 * of [P1.T1] is written once, in one place, with the restoring user as the actor.
 */
class RestoreDocumentVersionCommand extends AbstractCommand
{
	public function __construct(
		private readonly int $documentId,
		private readonly int $versionId,
		private readonly int $userId,
		// Passed through to the push so the tab that asked for this restore recognises the answer to its
		// own request; a restore that names no operation sends none.
		private readonly ?string $operationId = null,
		private readonly DocumentVersionRepository $versionRepository = new DocumentVersionRepository(),
	) {}

	protected function execute(): Result
	{
		$version = $this->versionRepository->getById($this->versionId);

		// IDOR guard: a version only restores into the document it was created from.
		// A caller-supplied $documentId that does not match the version's real owner
		// is indistinguishable from "version not found" to avoid leaking existence.
		if ($version === null || $version->getDocumentId() !== $this->documentId)
		{
			throw new DocumentNotFoundException();
		}

		return (new OverwriteDocumentContentCommand(
			documentId: $this->documentId,
			markdown: $version->getMarkdown(),
			userId: $this->userId,
			overwrite: true,
			title: $version->getTitle(),
			operationId: $this->operationId,
		))->run();
	}
}
