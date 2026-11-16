<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\ExternalAccess\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Infrastructure\Controller\ErrorCodeDictionary;
use Bitrix\Note\Internal\Service\ExternalAccess\PublicBacklinkService;

/**
 * Documents of the knowledge base that link to the one being read.
 *
 * The target document is checked by the scoped reader, the sources by the predicate of the service:
 * both questions are about the scope of the link and nothing else, so someone else's document is
 * neither a legal target nor a reported source.
 *
 * The two actions refuse differently on purpose. The list answers "not found" - the same answer a
 * missing document gets. The counter answers zero: it is drawn as a badge, and a refusal with a
 * code in place of a number would tell the client that the document exists but is not theirs, which
 * is exactly the difference a visitor may not learn.
 */
class BacklinkController extends BaseController
{
	/**
	 * @param int|null $afterSourceId raw SOURCE_ID keyset cursor of the previous page
	 * @return array{items: array[], nextCursor: ?int}|null
	 */
	public function getBacklinksAction(int $documentId, int $limit = 20, ?int $afterSourceId = null): ?array
	{
		if (!$this->isReadableTarget($documentId))
		{
			$code = ErrorCodeDictionary::EXTERNAL_ACCESS_NOT_FOUND;
			$this->addError(new Error(Loc::getMessage($code), $code));

			return null;
		}

		return $this->service()->getSources($documentId, $limit, $afterSourceId);
	}

	/**
	 * @return array{count: int, isCapped: bool}
	 */
	public function getBacklinksCountAction(int $documentId): array
	{
		if (!$this->isReadableTarget($documentId))
		{
			return ['count' => 0, 'isCapped' => false];
		}

		return $this->service()->getCount($documentId);
	}

	/**
	 * Built from the link context and never injected: the service has no method without a scope, so
	 * there is no way for an action added later to ask about a foreign knowledge base.
	 */
	private function service(): PublicBacklinkService
	{
		return new PublicBacklinkService($this->getExternalAccessContext());
	}

	private function isReadableTarget(int $documentId): bool
	{
		return $documentId > 0
			&& $this->getScopedDocumentReader()->hasDocument($documentId)
		;
	}
}
