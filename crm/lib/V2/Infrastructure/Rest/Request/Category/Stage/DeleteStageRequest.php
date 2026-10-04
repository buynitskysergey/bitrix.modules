<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.<entity>.category.stage.delete`: which stage to remove.
 *
 * As in {@see UpdateStageRequest}, the whole identifier of the stage addresses it and there is no
 * `filter`: removing a set is not a hidden ability of a typical method of CRM.
 */
final class DeleteStageRequest extends Request
{
	#[NotEmpty]
	public string $id;
}
