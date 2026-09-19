<?php

namespace Bitrix\Sign\Item\Integration\Im\Messages\Failure;

use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Integration\Im\Message;

class DocumentPreparationFailed extends Message
{
	public function __construct(
		int $fromUser,
		int $toUser,
		Document $document,
		string $link,
	)
	{
		parent::__construct($fromUser, $toUser);
		$this->document = $document;
		$this->link = $link;
	}

	public function getStageId(): string
	{
		return 'documentPreparationFailed';
	}

	public function getFallbackText(): string
	{
		return $this->getLocalizedFallbackMessage(
			'SIGN_CALLBACK_CHAT_DOCUMENT_PREPARATION_FAILED',
			[
				'#DOC_NAME#' => $this->getDocumentName($this->getDocument()),
				'#GRID_URL#' => $this->getLink(),
			]
		);
	}
}
