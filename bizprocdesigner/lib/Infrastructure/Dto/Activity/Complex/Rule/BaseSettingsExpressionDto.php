<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule;

/**
 * Expression DTO for BASE_SETTINGS constructions.
 *
 * BASE_SETTINGS has two modes, resolved during ConvertRuleCommand execution:
 *  - host-merged (default, actionId === null): activityData.Properties are merged directly
 *    into the host activity's Properties. No child activity is created, not present in Links.
 *  - node-action proxy (actionId !== null): the construction is bound to a node-action and is
 *    converted into a CHILD activity, exactly like an ActionExpressionDto.
 *
 * Analogous to ActionExpressionDto; actionId is nullable and defaults to null, which preserves
 * the original host-merged behavior for every existing base-settings payload.
 */
class BaseSettingsExpressionDto extends BaseExpressionDto
{
	/**
	 * Node-action code this base-settings is bound to. Null keeps the legacy host-merge behavior.
	 */
	public ?string $actionId;

	/**
	 * Raw activity data keyed by field names (pre-conversion payload from frontend).
	 * Nullable until the user fills the form.
	 */
	public ?array $rawActivityData;

	/**
	 * Processed activity data after frontend serialization.
	 * Properties sub-array is merged into the host activity's Properties (host-merge mode)
	 * or used as the child activity payload (node-action proxy mode).
	 */
	public ?array $activityData;

	public function __construct(
		?array $rawActivityData,
		?array $activityData,
		?string $actionId = null,
	)
	{
		$this->rawActivityData = $rawActivityData;
		$this->activityData = $activityData;
		$this->actionId = $actionId;
	}

	public function jsonSerialize(): array
	{
		return [
			'actionId' => $this->actionId,
			'rawActivityData' => $this->rawActivityData,
			'activityData' => $this->activityData,
		];
	}

	public static function fromArray(array $data): self
	{
		return new self(
			$data['rawActivityData'] ?? null,
			$data['activityData'] ?? null,
			$data['actionId'] ?? null,
		);
	}
}
