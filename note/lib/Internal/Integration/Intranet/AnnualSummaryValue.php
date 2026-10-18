<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Intranet;

use Bitrix\Main\Entity\EntityInterface;

/**
 * [DTO-01] One number of the annual summary: a metric of one employee.
 *
 * Implements EntityInterface from main rather than intranet's own SummaryInterface: the latter
 * lives in a private namespace of a foreign module, which note is not allowed to depend on. This
 * still reaches the receiver - EntityCollection type-checks items against EntityInterface, and
 * AnnualSummaryRepository::saveCollection() reads them by getUserId()/getId()/getTotal() alone.
 */
final class AnnualSummaryValue implements EntityInterface
{
	public function __construct(
		private readonly int $userId,
		private readonly string $name,
		private readonly int $total,
	)
	{
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	/**
	 * The metric name, not the user: this value lands in the NAME column of the summary row.
	 */
	public function getId(): string
	{
		return $this->name;
	}

	public function getTotal(): int
	{
		return $this->total;
	}
}
