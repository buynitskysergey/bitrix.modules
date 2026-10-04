<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Timeline\Activity\Email;

use Bitrix\Rest\V3\Dto\Dto;

class EmailActivityDto extends Dto
{
	public ?int $id;

	public ?int $entityId;

	public ?string $subject;

	public ?string $dateTime;

	public ?bool $isIncoming;

	public ?string $from;

	public ?array $to;

	public ?array $cc;

	public ?array $bcc;

	public ?array $bindings;

	public ?string $body;

	public ?bool $isBodyTruncated;

	public ?bool $isHidden;

	public ?int $activityId;

	public ?int $parentActivityId;

	public ?bool $isSyncedToImap;

	public ?array $warnings;
}
