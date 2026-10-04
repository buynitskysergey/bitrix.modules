<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Request;

use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * Dry run of a workflow graph: the graph to judge, in the same field an agent writes it with.
 *
 * The graph travels in fields so that the core converts it into the template dto and validates the form
 * against the add group - the very check the write path runs, which is what keeps the two verdicts one.
 * Not AddRequest: nothing is created here, and a dry run must not take part in idempotency.
 */
final class TemplateValidateRequest extends Request
{
	public FieldsStructure $fields;
}
