<?php

namespace Bitrix\Sign\Item\Integration\Im\Messages\Failure;

use Bitrix\Sign\Contract\Chat\Message\HasAnnulledCount;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Integration\Im\Message;

/**
 * HR-bot card: an annulment mark has been cleared, the signing is "Signed" again.
 *
 * Carries the actor (WithInitiator). The recipient role is a discriminator
 * inside the class ($toInitiator): the same class renders both the stage-id and
 * the fallback text for the signing employee and for the document initiator, so
 * two classes cover the four stage-ids (see STAGE-01). Texts are gender-neutral.
 *
 * The initiator card of a mass action reports how many marks were cleared,
 * otherwise an aggregated card reads as a single restored signing.
 */
class AnnulmentCanceled extends Message\WithInitiator implements HasAnnulledCount
{
	public function __construct(
		int $fromUser,
		int $toUser,
		int $initiatorUserId,
		string $initiatorName,
		Document $document,
		string $link,
		private readonly bool $toInitiator = false,
		private readonly ?int $annulledCount = null,
	)
	{
		parent::__construct($fromUser, $toUser, $initiatorUserId, $initiatorName);
		$this->document = $document;
		$this->link = $link;
	}

	public function getStageId(): string
	{
		return $this->toInitiator
			? 'annulmentCanceledToInitiator'
			: 'annulmentCanceledToEmployee'
		;
	}

	/**
	 * The employee card is about their own signing and never reports a number,
	 * whoever built the message.
	 */
	public function getAnnulledCount(): ?int
	{
		return $this->toInitiator ? $this->annulledCount : null;
	}

	public function getFallbackText(): string
	{
		$replace = [
			'#DOC_NAME#' => $this->getDocumentName($this->getDocument()),
			'#INITIATOR_NAME#' => $this->getInitiatorName(),
			'#GRID_URL#' => $this->getLink(),
		];

		if (!$this->toInitiator)
		{
			return $this->getLocalizedFallbackMessage(
				'SIGN_CALLBACK_CHAT_ANNULMENT_CANCELED_TO_EMPLOYEE',
				$replace,
			);
		}

		// A message written before the number existed carries none, and such an
		// action cleared a single mark.
		$count = $this->getAnnulledCount() ?? 1;

		return $this->getLocalizedFallbackMessagePlural(
			'SIGN_CALLBACK_CHAT_ANNULMENT_CANCELED_TO_INITIATOR',
			$count,
			$replace + ['#COUNT#' => (string)$count],
		);
	}
}
