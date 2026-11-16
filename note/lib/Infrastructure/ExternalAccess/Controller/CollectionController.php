<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\ExternalAccess\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Infrastructure\Controller\ErrorCodeDictionary;
use Bitrix\Note\Internal\Repository\CollectionRepository;

/**
 * The card of the knowledge base a public link leads to.
 *
 * There is no knowledge base parameter: the only one it can be is the one from the link context,
 * which the filter has already checked as alive and not archived. The answer carries the name and
 * nothing else - the description, the backlinks and the mentions belong to the task of the public
 * screen and arrive there through the same namespace and the same filter.
 */
class CollectionController extends BaseController
{
	public function __construct(
		?HttpRequest $request = null,
		private readonly CollectionRepository $repository = new CollectionRepository(),
	)
	{
		parent::__construct($request);
	}

	/**
	 * @return array{id: int, title: string}|null
	 */
	public function getAction(): ?array
	{
		$collectionId = $this->getExternalAccessContext()->getCollectionId();
		$collection = $this->repository->getById($collectionId);
		if ($collection === null)
		{
			$this->addError(new Error(
				Loc::getMessage(ErrorCodeDictionary::EXTERNAL_ACCESS_NOT_FOUND),
				ErrorCodeDictionary::EXTERNAL_ACCESS_NOT_FOUND,
			));

			return null;
		}

		return [
			'id' => $collectionId,
			'title' => (string)$collection->getName(),
		];
	}
}
