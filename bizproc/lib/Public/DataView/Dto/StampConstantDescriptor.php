<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Dto;

final class StampConstantDescriptor
{
	public function __construct(
		public readonly string $module,
		public readonly string $entity,
		public readonly array $params,
		public readonly string $title,
		public readonly string $type,
	) {
	}

	public function toArray(): array
	{
		return [
			'module' => $this->module,
			'entity' => $this->entity,
			'params' => $this->params,
			'title' => $this->title,
			'type' => $this->type,
		];
	}
}
