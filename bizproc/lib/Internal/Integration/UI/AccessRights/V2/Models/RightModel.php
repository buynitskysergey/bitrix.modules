<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Models;

/**
 * A single permission cell for the ui.accessrights.v2 builder: entity + action + the stored value.
 * The value is a template id, the «all» sentinel (-1) for a multivariables right, or the toggler value.
 */
final class RightModel implements \Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Models\RightModel
{
	public function __construct(
		public readonly string $entityId,
		public readonly string $actionId,
		public readonly int|string $value,
	)
	{
	}

	public function getValue(): int|string
	{
		return $this->value;
	}
}
