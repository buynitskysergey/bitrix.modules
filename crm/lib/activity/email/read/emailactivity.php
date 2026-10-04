<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email\Read;

use Bitrix\Crm\Activity\Email\MetaParser;
use Bitrix\Main\Type\DateTime;

final readonly class EmailActivity
{
	public function __construct(
		public int $id,
		public string $subject,
		public int $direction,
		public ?DateTime $startTime,
		public ?DateTime $createdTime,
		public ?DateTime $updatedTime,
		public ?DateTime $deadlineTime,
		public int $ownerTypeId,
		public int $ownerId,
		public string $providerId,
		public string $providerTypeId,
		public int $associatedEntityId,
		public ?int $parentId,
		public int $threadId,
		public int $authorId,
		public int $editorId,
		public int $responsibleId,
		public string $description,
		public int $descriptionTypeId,
		private MetaParser $meta,
	)
	{
	}

	public static function fromActivityRow(array $row): self
	{
		return new self(
			id: (int)($row['EMAIL_ACTIVITY_ID'] ?? $row['ID'] ?? 0),
			subject: (string)($row['SUBJECT'] ?? ''),
			direction: (int)($row['DIRECTION'] ?? 0),
			startTime: self::dateTimeOrNull($row['START_TIME'] ?? null),
			createdTime: self::dateTimeOrNull($row['CREATED'] ?? null),
			updatedTime: self::dateTimeOrNull($row['LAST_UPDATED'] ?? null),
			deadlineTime: self::dateTimeOrNull($row['DEADLINE'] ?? null),
			ownerTypeId: (int)($row['OWNER_TYPE_ID'] ?? 0),
			ownerId: (int)($row['OWNER_ID'] ?? 0),
			providerId: (string)($row['PROVIDER_ID'] ?? ''),
			providerTypeId: (string)($row['PROVIDER_TYPE_ID'] ?? ''),
			associatedEntityId: (int)($row['ASSOCIATED_ENTITY_ID'] ?? 0),
			parentId: self::positiveIntOrNull($row['PARENT_ID'] ?? null),
			threadId: (int)($row['THREAD_ID'] ?? 0),
			authorId: (int)($row['AUTHOR_ID'] ?? 0),
			editorId: (int)($row['EDITOR_ID'] ?? 0),
			responsibleId: (int)($row['RESPONSIBLE_ID'] ?? 0),
			description: (string)($row['DESCRIPTION'] ?? ''),
			descriptionTypeId: (int)($row['DESCRIPTION_TYPE'] ?? 0),
			meta: MetaParser::fromActivityFields($row),
		);
	}

	public function getTimelineTime(): ?DateTime
	{
		return $this->startTime ?? $this->createdTime;
	}

	public function getFrom(): string
	{
		return $this->meta->getFrom();
	}

	/**
	 * @return list<string>
	 */
	public function getFromList(): array
	{
		return $this->meta->getFromList();
	}

	/**
	 * @return list<string>
	 */
	public function getReplyTo(): array
	{
		return $this->meta->getReplyTo();
	}

	/**
	 * @return list<string>
	 */
	public function getTo(): array
	{
		return $this->meta->getTo();
	}

	/**
	 * @return list<string>
	 */
	public function getCc(): array
	{
		return $this->meta->getCc();
	}

	/**
	 * @return list<string>
	 */
	public function getBcc(): array
	{
		return $this->meta->getBcc();
	}

	/**
	 * @return list<string>
	 */
	public function getOwnerEmails(): array
	{
		return $this->meta->getOwnerEmails();
	}

	private static function dateTimeOrNull(mixed $value): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		if (is_string($value) && trim($value) !== '')
		{
			return DateTime::tryParse($value);
		}

		return null;
	}

	private static function positiveIntOrNull(mixed $value): ?int
	{
		$value = (int)$value;

		return $value > 0 ? $value : null;
	}
}
