<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\ExternalAccess\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Infrastructure\Controller\ErrorCodeDictionary;
use Bitrix\Note\Internal\Exceptions\CollectionTreeTooLargeException;
use Bitrix\Note\Internal\Service\File\DocumentFilesMapService;
use Bitrix\Note\Internal\Service\ExternalAccess\PublicResponseMapper;

/**
 * Public reading of one document by a link.
 *
 * The action has no "knowledge base" parameter: the scope comes from the link context the filter
 * set, and the document is read through the scoped reader. Everything the scope does not contain
 * answers one and the same "not found" - someone else's, an archived, a recycled, an orphaned and
 * the hidden main document alike.
 *
 * The view of a visitor is not tracked: the public actions simply do not call the tracking.
 */
class DocumentController extends BaseController
{
	public function __construct(
		?HttpRequest $request = null,
		private readonly PublicResponseMapper $mapper = new PublicResponseMapper(),
	)
	{
		parent::__construct($request);
	}

	public function getAction(int $documentId): ?array
	{
		$reader = $this->getScopedDocumentReader();
		$document = $reader->getDocument($documentId);
		if ($document === null)
		{
			$this->addError(new Error(
				Loc::getMessage(ErrorCodeDictionary::EXTERNAL_ACCESS_NOT_FOUND),
				ErrorCodeDictionary::EXTERNAL_ACCESS_NOT_FOUND,
			));

			return null;
		}

		return [
			...$this->mapper->mapDocument($document, $reader->resolveVisibleParentId($document)),
			'files' => (object)(new DocumentFilesMapService())->build($documentId, public: true),
		];
	}

	/**
	 * The tree of the scope of the link, with its own white list of node fields: the
	 * knowledge base of the ordinary node is not in it, and the breadcrumbs the client needs it
	 * builds from this tree itself.
	 *
	 * A knowledge base too large to read whole is refused rather than answered with a part of itself:
	 * the client draws the panel and the breadcrumbs from this one tree and has no way to ask for the
	 * rest, so a piece of it would look exactly like the whole. The tab of all articles pages and
	 * stays open to such a base.
	 *
	 * @return array{items: array[], truncated: bool}|null
	 */
	public function getTreeAction(): ?array
	{
		try
		{
			return $this->getScopedTreeReader()->getTree();
		}
		catch (CollectionTreeTooLargeException)
		{
			$this->addError(new Error(
				Loc::getMessage(ErrorCodeDictionary::EXTERNAL_ACCESS_TREE_TOO_LARGE),
				ErrorCodeDictionary::EXTERNAL_ACCESS_TREE_TOO_LARGE,
			));

			return null;
		}
	}

	/**
	 * The flat list of articles of the base behind the link - the "All articles" tab of a visitor.
	 *
	 * A list and not the tree: the tab shows cards with a preview under the title, and the tree
	 * deliberately carries none. It also pages, so a base above the tree budget stops being a base
	 * whose tab is silently cut short.
	 *
	 * @return array{items: array[], nextCursor: array{position: int, id: int}|null}
	 */
	public function listAction(int $limit = 50, ?array $afterCursor = null): array
	{
		return $this->getScopedListReader()->getList($limit, $afterCursor);
	}

	/**
	 * A page of the children of one document of the base behind the link: the block of child articles
	 * inside an article and the branch the panel opens.
	 *
	 * The same action the ordinary circuit has (note.infrastructure.DocumentController.listByParent)
	 * and the same row - the preview, the date and the "has nested" flag - without the knowledge base
	 * parameter: the base is the one of the link. A parent from another base answers with an empty
	 * page, exactly as a parent that does not exist does.
	 *
	 * @return array{documents: array[], nextCursor: array{position: int, id: int}|null}
	 */
	public function listByParentAction(
		?int $parentId = null,
		int $limit = 50,
		?array $afterCursor = null,
	): array
	{
		return $this->getScopedListReader()->getListByParent($parentId, $limit, $afterCursor);
	}
}
