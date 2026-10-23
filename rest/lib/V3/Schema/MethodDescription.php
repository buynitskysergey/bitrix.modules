<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Mcp\McpToolMetadata;

class MethodDescription implements \Serializable
{
	public function __construct(
		public readonly string $module,
		public readonly ?string $controllerFqcn,
		public readonly string $method,
		public readonly ?string $dtoFqcn,
		public readonly array $scopes,
		public readonly string $actionUri,
		public readonly LocalizableMessage|string|null $title = null,
		public readonly LocalizableMessage|string|null $description = null,
		public readonly bool $isEnabled = true,
		public readonly ?array $queryParams = null,
		public readonly bool $isHidden = false,
		public readonly ?McpToolMetadata $mcpTool = null,
		public readonly bool $isDeprecated = false,
	) {
	}

	/**
	 * Whether the route is excluded from the MCP export: ControllerDataManager drops its
	 * mcpTool, so a null metadata on such a route means "removed", not "never declared".
	 */
	public function isMcpExportSuppressed(): bool
	{
		return $this->isHidden || $this->isDeprecated || !$this->isEnabled;
	}

	public function serialize(): ?string
	{
		return serialize($this->__serialize());
	}

	public function unserialize(string $data): void
	{
		$this->__unserialize(unserialize($data, ['allowed_classes' => false]));
	}

	public function __serialize(): array
	{
		return [
			'module' => $this->module,
			'controllerFqcn' => $this->controllerFqcn,
			'method' => $this->method,
			'dtoFqcn' => $this->dtoFqcn,
			'scopes' => $this->scopes,
			'actionUri' => $this->actionUri,
			'title' => $this->title,
			'description' => $this->description,
			'isEnabled' => $this->isEnabled,
			'queryParams' => $this->queryParams,
			'isHidden' => $this->isHidden,
			'mcpTool' => $this->mcpTool?->toArray(),
			'isDeprecated' => $this->isDeprecated,
		];
	}

	public function __unserialize(array $data): void
	{
		$this->module = $data['module'];
		$this->controllerFqcn = $data['controllerFqcn'];
		$this->method = $data['method'];
		$this->dtoFqcn = $data['dtoFqcn'];
		$this->scopes = $data['scopes'];
		$this->actionUri = $data['actionUri'];
		$this->title = $data['title'];
		$this->description = $data['description'];
		$this->isEnabled = $data['isEnabled'];
		$this->queryParams = $data['queryParams'];
		$this->isHidden = $data['isHidden'] ?? false;
		$this->mcpTool = McpToolMetadata::fromArray($data['mcpTool'] ?? null);
		$this->isDeprecated = $data['isDeprecated'] ?? false;
	}
}
