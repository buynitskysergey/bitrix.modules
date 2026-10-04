<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Mail;

use Bitrix\Mail\Public\Service\Draft\Dto\DraftOwnedAttachment;
use Bitrix\Mail\Public\Service\Draft\Dto\DraftSendClaim;
use Bitrix\Mail\Public\Service\Draft\DraftFeature;
use Bitrix\Mail\Public\Service\Draft\DraftSendService;
use Bitrix\Main\Loader;

final class Draft
{
	public static function isAvailable(): bool
	{
		return self::hasDraftApi() && DraftFeature::isAvailable();
	}

	/**
	 * The draft the message was built from is completed under the identity of the compose form that
	 * owns it, the same one its files are released under: a draft another device has taken over is a
	 * different draft, and completing it would drop the work of that device.
	 */
	public static function completeAfterSuccessfulSend(
		int $userId,
		mixed $draftId,
		int $crmEntityTypeId,
		int $crmEntityId,
		mixed $expectedRevision,
		mixed $expectedClientId = null,
	): void
	{
		if (!self::hasDraftApi())
		{
			return;
		}

		DraftSendService::completeAfterSuccessfulSend(DraftSendClaim::forCrm(
			$userId,
			$draftId,
			$crmEntityTypeId,
			$crmEntityId,
			$expectedRevision,
			$expectedClientId,
		));
	}

	/**
	 * Attachment copies the given user's draft owns, in the order of the draft. The send carries the
	 * copies over itself, because the client reports a restored attachment by its Disk object id,
	 * which the send gate cannot resolve.
	 *
	 * Which files a draft owns is decided by the mail module: it holds the draft, and the checks of
	 * owner, context, revision and compose form identity belong there together with it. Whether the
	 * user may still take the files out of that CRM entity is decided here, by CRM.
	 *
	 * @return list<array{fileId: int, sourceFileId: int, objectId: int}>
	 */
	public static function getOwnedAttachments(
		int $userId,
		mixed $draftId,
		int $crmEntityTypeId,
		int $crmEntityId,
		mixed $expectedRevision,
		mixed $expectedClientId = null,
	): array
	{
		if (!self::hasDraftApi())
		{
			return [];
		}

		$claim = DraftSendClaim::forCrm(
			$userId,
			$draftId,
			$crmEntityTypeId,
			$crmEntityId,
			$expectedRevision,
			$expectedClientId,
		);

		// An ordinary send names no draft, and the permissions of the draft entity cost an attribute read
		// for every non-administrator: nothing is spent on the claim before the client makes one.
		if (!DraftSendService::isClaimed($claim) || !self::canWriteToEntity($userId, $crmEntityTypeId, $crmEntityId))
		{
			return [];
		}

		return array_map(
			static fn(DraftOwnedAttachment $attachment): array => [
				'fileId' => $attachment->fileId,
				'sourceFileId' => $attachment->sourceFileId,
				'objectId' => $attachment->objectId,
			],
			DraftSendService::getOwnedAttachments($claim),
		);
	}

	/**
	 * The draft may sit on another entity than the message being sent, and the send gate checks only the
	 * entity the message ends up on. Access to the draft entity can be gone since the draft was composed,
	 * so the right to write mail into it is re-checked here, by the same gate the send path uses for its
	 * own owner and for the permissions of the user the draft is claimed for.
	 */
	private static function canWriteToEntity(int $userId, int $crmEntityTypeId, int $crmEntityId): bool
	{
		return $userId > 0
			&& $crmEntityTypeId > 0
			&& $crmEntityId > 0
			&& \CCrmActivity::CheckUpdatePermission(
				$crmEntityTypeId,
				$crmEntityId,
				\CCrmPerms::GetUserPermissions($userId),
			)
		;
	}

	/**
	 * The mail module is released on its own, so a version without internal drafts is a normal state
	 * here: drafts are then simply unavailable and every entry point above answers as if the feature
	 * were switched off.
	 */
	private static function hasDraftApi(): bool
	{
		return Loader::includeModule('mail')
			&& class_exists(DraftFeature::class)
			&& method_exists(DraftFeature::class, 'isAvailable')
			&& class_exists(DraftSendService::class)
			&& method_exists(DraftSendService::class, 'isClaimed')
			&& method_exists(DraftSendService::class, 'getOwnedAttachments')
			&& method_exists(DraftSendService::class, 'completeAfterSuccessfulSend')
			&& class_exists(DraftSendClaim::class)
			&& method_exists(DraftSendClaim::class, 'forCrm')
			&& class_exists(DraftOwnedAttachment::class)
		;
	}
}
