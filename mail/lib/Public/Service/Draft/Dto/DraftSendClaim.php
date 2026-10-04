<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Draft\Dto;

use Bitrix\Mail\Internals\DraftTable;

/**
 * What a sending client claims about the draft it is sending.
 *
 * The identifiers are kept exactly as the client reported them: how a reported value becomes a draft
 * identity is a rule of the mail module, so a consumer neither repeats it nor has to know the draft
 * context values.
 */
final class DraftSendClaim
{
	private function __construct(
		public readonly int $userId,
		public readonly string $contextType,
		public readonly mixed $draftId,
		public readonly mixed $expectedRevision,
		public readonly mixed $expectedClientId,
		public readonly ?int $crmEntityTypeId,
		public readonly ?int $crmEntityId,
	)
	{
	}

	public static function forMail(
		int $userId,
		mixed $draftId,
		mixed $expectedRevision,
		mixed $expectedClientId = null,
	): self
	{
		return new self(
			userId: $userId,
			contextType: DraftTable::CONTEXT_MAIL,
			draftId: $draftId,
			expectedRevision: $expectedRevision,
			expectedClientId: $expectedClientId,
			crmEntityTypeId: null,
			crmEntityId: null,
		);
	}

	public static function forCrm(
		int $userId,
		mixed $draftId,
		int $crmEntityTypeId,
		int $crmEntityId,
		mixed $expectedRevision,
		mixed $expectedClientId = null,
	): self
	{
		return new self(
			userId: $userId,
			contextType: DraftTable::CONTEXT_CRM,
			draftId: $draftId,
			expectedRevision: $expectedRevision,
			expectedClientId: $expectedClientId,
			crmEntityTypeId: $crmEntityTypeId,
			crmEntityId: $crmEntityId,
		);
	}
}
