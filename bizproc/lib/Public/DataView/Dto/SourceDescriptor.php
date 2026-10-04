<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Dto;

final class SourceDescriptor
{
	public function __construct(
		public readonly string $module,
		public readonly string $entity,
		public readonly string $title,
		public readonly bool $requiresParams,
		public readonly bool $bounded,
		public readonly array $params = [],
	) {
	}

	public function toSourceRef(): SourceRef
	{
		return new SourceRef($this->module, $this->entity, $this->params);
	}

	public function toArray(): array
	{
		return [
			'module' => $this->module,
			'entity' => $this->entity,
			'title' => $this->title,
			'requiresParams' => $this->requiresParams,
			'bounded' => $this->bounded,
			'params' => $this->params,
		];
	}
}
