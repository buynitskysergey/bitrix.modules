<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Source;

interface SourceInterface
{
	public function checkConnection(): SourceResult;

	public function getCollections(): SourceResult;

	public function getDocumentTree(string $collectionId): SourceResult;

	/**
	 * One batch of the source listing of the collection, starting at $offset.
	 *
	 * data:
	 *   - documents: the pages of this batch;
	 *   - consumed: int, optional - how far down its own listing the source got here. Not the same
	 *     number as the documents returned: an element the source listed but could not read
	 *     afterwards yields no document and still occupies the window;
	 *   - windowLimit: int, optional - the window this batch was answered with, which is $limit
	 *     unless the source has a page ceiling of its own and handed out less.
	 *
	 * {@see \Bitrix\Note\Internal\Service\Import\Step\FillContentStep} advances $offset by `consumed`
	 * and reads a batch shorter than `windowLimit` as the end of the collection. A source that names
	 * neither is paged by the documents it returned, against the $limit it was given: for such a
	 * source a batch is short only when there is nothing left to give. A source whose listing can
	 * outrun its documents, or whose ceiling is below $limit, has to name them - otherwise the first
	 * batch that is short for another reason is read as the end of the collection and the rest of it
	 * is never converted.
	 */
	public function getDocumentsPage(string $collectionId, int $offset, int $limit): SourceResult;

	public function downloadAttachment(string $attachmentId): SourceResult;

	/**
	 * Access control list of the source collection, expressed in note terms so it can be applied
	 * verbatim by {@see \Bitrix\Note\Internal\Service\Import\CollectionAccessTransfer}.
	 *
	 * data:
	 *   - permissions: array<array{subjectCode: string, level: string}> — positive
	 *     grants only (Bitrix access codes + note level codes view|manage|moderate);
	 *   - policyLevel: ?string - the '*' fallback level (none|view|manage|moderate), or null when
	 *     the source names no policy at all. Not the same statement as `none`, which says the base
	 *     is open to nobody the source named, so a source that means that must say it ([P6.T5]);
	 *   - nothingToTransfer: bool, set to true by a source that has nothing to hand over. An empty
	 *     permission list is not that statement on its own - it is the shape a source that could
	 *     not be read produces as well - so a source that knows the difference says so here.
	 *
	 * Nothing to transfer, however stated, leaves the collection with the access the conversion
	 * created for it. A source with no notion of source-side permissions (e.g. Outline) names
	 * neither a grant nor a policy.
	 */
	public function getCollectionAccess(string $collectionId): SourceResult;
}
