<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.<entity>.category.stage.get`: which stage to read.
 *
 * The standard {@see \Bitrix\Rest\V3\Interaction\Request\IdRequest} asks for the string identifier
 * and for nothing else, which is exactly what this method needs - no `select`, since a stage is read
 * whole, and no `filter`. What it does not do is refuse an empty one, while
 * {@see UpdateStageRequest} and {@see DeleteStageRequest} do: the three methods address a stage the
 * same way and are answered the same way when the address is missing.
 */
final class GetStageRequest extends Request
{
	#[NotEmpty]
	public string $id;
}
