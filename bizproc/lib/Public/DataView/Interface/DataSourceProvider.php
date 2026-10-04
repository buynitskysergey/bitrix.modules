<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Interface;

use Bitrix\Bizproc\Public\DataView\Dto\ExtractQuery;
use Bitrix\Bizproc\Public\DataView\Dto\SourceDescriptor;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRelation;
use Bitrix\Bizproc\Public\DataView\Dto\SourceSchema;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;

interface DataSourceProvider
{
	public function getModuleId(): string;

	public function getAvailableSources(int $actorId): array;

	/** @throws SourceUnavailableException */
	public function getSourceSchema(SourceRef $source): SourceSchema;

	/**
	 * @return iterable<array<string, scalar|null|array>>
	 */
	public function extract(SourceRef $source, ExtractQuery $query, int $actorId): iterable;

	public function getRelations(SourceRef $source): array;
}
