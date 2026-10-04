<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

/**
 * One rule of a complex-node input port (DTO-01), symmetric to the domain
 * {@see \Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\RuleDto}: a stable id plus an
 * ordered list of constructions (order is significant).
 */
final class AgentComplexRule implements Arrayable
{
	/**
	 * @param string $id stable rule identifier
	 * @param list<AgentComplexConstruction> $constructions ordered constructions
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $constructions,
	) {}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'constructions' => array_map(
				static fn(AgentComplexConstruction $construction): array => $construction->toArray(),
				$this->constructions,
			),
		];
	}
}
