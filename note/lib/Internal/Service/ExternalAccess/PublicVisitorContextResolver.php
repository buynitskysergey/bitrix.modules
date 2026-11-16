<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;

/**
 * The link a visitor without a token is standing in, found from what the request does carry.
 *
 * The public file paths name a file or a document and nothing else: a token in the url of every
 * picture would leak the link into referers, caches and copied image addresses. The verdict of the
 * attachment connector has even less to work with - Disk asks it about an attached object, out of
 * any request of ours. Both walk the same chain: the thing named by the request leads to a knowledge
 * base, the marks of the visitor session lead from that base to the link records this visitor has
 * actually opened, and each candidate is then asked the ordinary questions of the circuit through
 * the one resolver that owns them.
 *
 * A candidate has to be both valid and confirmed. Validity is the whole chain of PublicLinkResolver
 * - disabled, expired, archived base, closed tariff - so a revoke closes the files of a page that is
 * still open in a browser. A reissue is caught one step earlier, by the mark itself: the mark carries
 * the fingerprint of the token it was given for, so a mark of a previous generation never becomes a
 * candidate. Confirmation is required because a file is the content of the knowledge base, not a part
 * of the password prompt: without it a link with a password would hand out its attachments to anyone
 * who knows the token.
 *
 * Several candidates are normal - one visitor can hold marks of two links into the same base - and
 * the first one that answers everything wins. Which one it is does not matter: this release knows a
 * single access level, so two valid links into one base open exactly the same content.
 */
class PublicVisitorContextResolver
{
	public function __construct(
		private readonly PublicLinkResolver $linkResolver = new PublicLinkResolver(),
		private readonly VisitorSessionService $visitorSession = new VisitorSessionService(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly DocumentFileLinkRepository $fileLinkRepository = new DocumentFileLinkRepository(),
	) {}

	/** @var array|null the row forFile() walked through, see getResolvedFileLink() */
	private ?array $resolvedFileLink = null;

	public function forCollection(int $collectionId): ?ResolvedPublicLink
	{
		if ($collectionId <= 0)
		{
			return null;
		}

		foreach ($this->visitorSession->contextsForCollection($collectionId) as $linkRecordId)
		{
			$link = $this->linkResolver->resolveByRecordId($linkRecordId);
			if ($link !== null && $this->visitorSession->isConfirmed($linkRecordId))
			{
				return $link;
			}
		}

		return null;
	}

	/**
	 * The knowledge base of the document is read here and the document itself is not checked any
	 * further: whether it is alive and inside the scope of the link is the question of
	 * ScopedDocumentReader, and answering it twice in two places is how the two answers drift apart.
	 */
	public function forDocument(int $documentId): ?ResolvedPublicLink
	{
		if ($documentId <= 0)
		{
			return null;
		}

		$collectionId = $this->documentRepository->getCollectionIds([$documentId])[$documentId] ?? 0;

		return $this->forCollection($collectionId);
	}

	public function forFile(int $fileId): ?ResolvedPublicLink
	{
		if ($fileId <= 0)
		{
			return null;
		}

		$link = $this->fileLinkRepository->getLinkByFileId($fileId);
		if ($link === null)
		{
			return null;
		}

		$this->resolvedFileLink = $link;

		return $this->forDocument((int)$link['DOCUMENT_ID']);
	}

	/**
	 * The row forFile() read on its way to the link, so that the caller need not read it again.
	 *
	 * The file paths pay this read twice per picture otherwise - once to learn which link the visitor
	 * is standing in, once inside the action to learn which document the file belongs to - and a page
	 * of a knowledge base is a page full of pictures.
	 *
	 * The memory is the instance and nothing wider: a resolver is built for one request and nothing
	 * kept here outlives it.
	 *
	 * @return array|null the row of DocumentFileLinkRepository, null when forFile() found none
	 */
	public function getResolvedFileLink(): ?array
	{
		return $this->resolvedFileLink;
	}
}
