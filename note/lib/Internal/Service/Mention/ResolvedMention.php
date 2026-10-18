<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Mention;

final class ResolvedMention
{
	public function __construct(
		public readonly string $type,
		public readonly int $id,
		public readonly ?string $label,
		public readonly ?string $avatar,
		public readonly ?string $url,
		public readonly bool $available,
		public readonly ?string $reason,
		public readonly bool $isCurrentUser,
	) {
	}

	public static function unavailable(string $type, int $id, string $reason, ?string $label = null): self
	{
		return new self(
			type: $type,
			id: $id,
			label: $label,
			avatar: null,
			url: null,
			available: false,
			reason: $reason,
			isCurrentUser: false,
		);
	}

	public static function available(
		string $type,
		int $id,
		string $label,
		?string $url,
		?string $avatar = null,
		bool $isCurrentUser = false,
	): self {
		return new self(
			type: $type,
			id: $id,
			label: $label,
			avatar: $avatar,
			url: $url,
			available: true,
			reason: null,
			isCurrentUser: $isCurrentUser,
		);
	}

	public function toArray(): array
	{
		return [
			'type' => $this->type,
			'id' => $this->id,
			'label' => $this->label,
			'avatar' => $this->avatar,
			'url' => $this->url,
			'available' => $this->available,
			'reason' => $this->publicReason(),
			'isCurrentUser' => $this->isCurrentUser,
		];
	}

	// Do not leak deleted-vs-no_access outward: for arbitrary ids that distinction is an
	// existence oracle. 'invalid' (malformed request type) is safe; the rest collapse.
	private function publicReason(): ?string
	{
		if ($this->reason === null || $this->reason === 'invalid')
		{
			return $this->reason;
		}

		return 'unavailable';
	}
}
