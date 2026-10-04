<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto\Message;

use Bitrix\Main\Type\DateTime;

/**
 * A valid start-date cache entry of a folder. Its whole point is to tell a valid cache holding no date -
 * an empty folder, which this DTO carries as a null value - from a cache that has to be recalculated,
 * for which the reader returns no DTO at all.
 */
final readonly class StartDateCacheDto
{
	public function __construct(
		public ?DateTime $value = null,
	)
	{
	}
}
