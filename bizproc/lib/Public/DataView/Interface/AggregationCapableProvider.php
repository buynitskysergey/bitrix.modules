<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Interface;

use Bitrix\Bizproc\Public\DataView\Dto\AggregateQuery;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;

interface AggregationCapableProvider
{
	/**
	 * @return iterable<array<string, scalar|null>>
	 * @throws SourceUnavailableException
	 */
	public function aggregate(SourceRef $source, AggregateQuery $query, int $actorId): iterable;
}
