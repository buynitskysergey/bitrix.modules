<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\ExternalAccess\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Infrastructure\Controller\ErrorCodeDictionary;
use Bitrix\Note\Internal\Service\ExternalAccess\PublicMentionService;

/**
 * The mentions written in one document of the scope of a public link.
 *
 * The document is a required parameter, and that is not a convenience: the ordinary
 * `MentionController::resolveMentionsBatch` takes a list of `{type, id}` from the client, and in the
 * public circuit such a list is a way to walk over foreign documents and to download the directory
 * of employees. Here the list is collected by the server from the text of the document, so nothing
 * can be asked about that the document itself does not mention. A list from the client may only
 * narrow that set further - see the action.
 *
 * The same action serves the markdown download of the client: before serialising the editor it
 * needs the labels of the mentions, and it has nowhere else to ask for them.
 */
class MentionController extends BaseController
{
	/**
	 * `isCapped` says the list was cut at the ceiling of the server: the client asked about a
	 * document and has no other way to learn that some of its mentions are missing from the answer
	 * (the name is the one the counter of backlinks already uses for the same thing).
	 *
	 * `items` narrows the answer to the mentions the client is about to draw. The document is still
	 * the subject of the question and is still checked against the scope of the link before anything
	 * is read; the named pairs are then resolved inside that scope, which is what the public tree of
	 * the link publishes anyway (see PublicMentionService). An absent or empty `items` asks about the
	 * whole document, exactly as before.
	 *
	 * @param array<array{type: string, id: int}> $items
	 * @return array{mentions: array[], isCapped: bool}|null
	 */
	public function listByDocumentAction(int $documentId, array $items = []): ?array
	{
		if ($documentId <= 0 || !$this->getScopedDocumentReader()->hasDocument($documentId))
		{
			$code = ErrorCodeDictionary::EXTERNAL_ACCESS_NOT_FOUND;
			$this->addError(new Error(Loc::getMessage($code), $code));

			return null;
		}

		return (new PublicMentionService($this->getExternalAccessContext()))
			->listByDocument($documentId, $items)
		;
	}
}
