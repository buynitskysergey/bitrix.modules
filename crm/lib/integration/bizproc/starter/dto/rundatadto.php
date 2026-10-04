<?php

namespace Bitrix\Crm\Integration\BizProc\Starter\Dto;

final class RunDataDto
{
	public function __construct(
		/**
		 * Fields carried by this run: what an update writes, or what the item changed within it.
		 * An empty array is a collected result and means "the update changed nothing";
		 * a run that collects no fields at all passes null instead.
		 */
		public readonly ?array $actualFields = null,
		public readonly ?array $previousFields = null,
		/** @var EventDto[] */
		public readonly array $events = [],
		public readonly int $userId = 0,
		public readonly array | string | null $parameters = null,
		public readonly string $scope = '',
		public readonly ?int $delay = null,
		public readonly bool $isManual = false,
		public readonly ?int $categoryId = null,
	)
	{}
}
