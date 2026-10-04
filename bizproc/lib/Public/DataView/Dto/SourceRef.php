<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Dto;

final class SourceRef
{
	public function __construct(
		public readonly string $module,
		public readonly string $entity,
		public readonly array $params = [],
	) {
	}

	public static function fromArray(array $source): self
	{
		return new self(
			module: (string)($source['module'] ?? ''),
			entity: (string)($source['entity'] ?? ''),
			params: (array)($source['params'] ?? []),
		);
	}

	public function getParam(string $key, mixed $default = null): mixed
	{
		return $this->params[$key] ?? $default;
	}
}
