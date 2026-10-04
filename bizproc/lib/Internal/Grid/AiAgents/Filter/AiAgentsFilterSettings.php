<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Filter;

use Bitrix\Main\Filter\Settings;

class AiAgentsFilterSettings extends Settings
{
	public const LAUNCHED_BY_FIELD = 'LAUNCHED_BY';
	public const AGENT_TEMPLATE_FIELD = 'AGENT_TEMPLATE';
	public const IS_ACTIVE_FIELD = 'IS_ACTIVE';

	private const DECLARED_FIELDS = [
		self::LAUNCHED_BY_FIELD,
		self::AGENT_TEMPLATE_FIELD,
		self::IS_ACTIVE_FIELD,
	];

	protected array $filterAvailability = [];

	public function __construct(array $params)
	{
		parent::__construct($params);
		$this->initFilterAvailability();
	}

	public function getFilterAvailability(): array
	{
		return $this->filterAvailability;
	}

	public function isFilterAvailable(string $filterField): bool
	{
		return $this->getFilterAvailability()[$filterField] ?? true;
	}

	/**
	 * Declared filter fields — the single source of truth for which fields the grid
	 * is allowed to apply to the query. Independent from grid column visibility:
	 * a filter-only field (e.g. AGENT_TEMPLATE) is not a grid column but is filterable.
	 *
	 * @return list<string>
	 */
	public function getWhiteList(): array
	{
		return self::DECLARED_FIELDS;
	}

	private function initFilterAvailability(): void
	{
		$this->filterAvailability = array_fill_keys(self::DECLARED_FIELDS, true);
	}
}
