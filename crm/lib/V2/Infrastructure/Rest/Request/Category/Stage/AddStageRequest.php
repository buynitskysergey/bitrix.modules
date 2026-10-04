<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category\StageDto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.<entity>.category.stage.add`: the fields of a new stage, the category it goes into among them.
 *
 * The category travels inside `fields` rather than as a parameter of the method: that is where the
 * parent of a child resource belongs, and a typical method of CRM takes nothing beside the standard
 * request. That `fields.categoryId` is demanded, and that `0` is one of its values - the default
 * category of Deal - is the business of the contract of a stage ({@see StageDto}) rather than of this
 * request.
 */
final class AddStageRequest extends Request
{
	public FieldsStructure $fields;
}
