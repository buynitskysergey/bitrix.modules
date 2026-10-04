<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Draft;

use Bitrix\Mail\Internal\Repository\DraftRepository;
use Bitrix\Mail\Internal\Service\Draft\DraftCompletion;
use Bitrix\Mail\Public\Service\Draft\Dto\DraftOwnedAttachment;
use Bitrix\Mail\Public\Service\Draft\Dto\DraftSendClaim;

/**
 * What a module that owns its own send path needs from an internal draft: the files the draft owns and
 * the completion of the draft once the message is out. Every ownership rule stays here, so a consumer
 * never reaches into the draft storage of the mail module.
 */
final class DraftSendService
{
	/**
	 * Attachment copies the claimed draft owns, in the order of the draft.
	 *
	 * The files are released only for the draft the client says it is sending: the same owner, an active
	 * and unexpired draft of the claimed context, the same revision the send was built on and, when the
	 * client reports its own identity, the compose form that owns the draft. A draft another device has
	 * taken over is a different draft, and its files were never in that form.
	 *
	 * A switched off feature releases nothing: with drafts unavailable no compose form produces a claim,
	 * and a stored draft file must not reach an outgoing message on the word of the client alone.
	 *
	 * @return list<DraftOwnedAttachment>
	 */
	public static function getOwnedAttachments(DraftSendClaim $claim): array
	{
		if (!self::isClaimed($claim) || !DraftFeature::isAvailable())
		{
			return [];
		}

		return array_map(
			static fn(array $attachment): DraftOwnedAttachment => new DraftOwnedAttachment(
				$attachment['fileId'],
				$attachment['sourceFileId'],
				$attachment['objectId'],
			),
			(new DraftRepository())->findClaimedAttachments(
				userId: $claim->userId,
				draftId: self::toId($claim->draftId),
				contextType: $claim->contextType,
				expectedRevision: self::toId($claim->expectedRevision),
				expectedClientId: self::toClientId($claim->expectedClientId),
				crmEntityTypeId: $claim->crmEntityTypeId,
				crmEntityId: $claim->crmEntityId,
			),
		);
	}

	/**
	 * Whether the client claims a draft at all. A send that names no draft, or names one the mail module
	 * cannot read as a draft identity, is an ordinary send: a consumer asks this before it spends anything
	 * on the claim, and how a reported value becomes a draft identity stays a rule of the mail module.
	 */
	public static function isClaimed(DraftSendClaim $claim): bool
	{
		return $claim->userId > 0
			&& self::toId($claim->draftId) > 0
			&& self::toId($claim->expectedRevision) > 0
		;
	}

	/**
	 * Best-effort completion of the claimed draft: the message is already out, so a conflict of
	 * ownership or of revision is logged by the mail module and never reported back as a send failure.
	 *
	 * The draft is completed under the same identity its files are released under: owner, context,
	 * revision and, when the client reports one, the compose form that owns the draft. A draft another
	 * device has taken over is a different draft, and completing it would drop the work of that device.
	 *
	 * Completion is not held by the feature gate: it only closes a draft of the sending user, and the
	 * drafts that were active when the feature was switched off have to be closable, otherwise they keep
	 * their unique context slot until they expire.
	 */
	public static function completeAfterSuccessfulSend(DraftSendClaim $claim): void
	{
		$draftId = self::toId($claim->draftId);
		if ($claim->userId <= 0 || $draftId <= 0)
		{
			return;
		}

		DraftCompletion::afterSuccessfulSend(
			userId: $claim->userId,
			draftId: $draftId,
			contextType: $claim->contextType,
			crmEntityTypeId: $claim->crmEntityTypeId,
			crmEntityId: $claim->crmEntityId,
			expectedRevision: $claim->expectedRevision,
			expectedClientId: self::toClientId($claim->expectedClientId),
		);
	}

	/**
	 * The identity a client reported, or null when it reported none: a client of an older build knows
	 * nothing about compose form identity, and it must not be locked out of its own draft.
	 */
	private static function toClientId(mixed $value): ?string
	{
		$value = is_string($value) ? trim($value) : '';

		return $value === '' ? null : $value;
	}

	private static function toId(mixed $value): int
	{
		return is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1) ? (int)$value : 0;
	}
}
