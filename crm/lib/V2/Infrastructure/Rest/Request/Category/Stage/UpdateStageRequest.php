<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.<entity>.category.stage.update`: which stage and what to write into it.
 *
 * `id` is the whole identifier of the stage, the way the contract publishes it - `C2:NEW` for a
 * category of Deal, `DT128_5:NEW` for a smart process one. It says which category the stage stands in
 * as well, so nothing else has to address it.
 *
 * A request of its own rather than the standard `UpdateRequest`, which addresses a set by `filter`:
 * a typical method of CRM is forbidden to do that, and the property is absent here rather than empty.
 *
 * Empty `fields` is a valid request rather than an error: it asks for no write, and answering it
 * still takes checking that the stage exists and may be changed.
 *
 * `fields.categoryId` is refused: a stage does not move between categories. The refusal is given by
 * the controller of the family - the framework lets the field through, and dropping a field a client
 * sent in silence is not on offer.
 */
final class UpdateStageRequest extends Request
{
	#[NotEmpty]
	public string $id;

	public FieldsStructure $fields;
}
