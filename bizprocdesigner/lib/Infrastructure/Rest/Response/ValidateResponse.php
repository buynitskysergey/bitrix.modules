<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Response;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\ValidationReportDto;
use Bitrix\Rest\V3\Interaction\Response\Response;

/**
 * Response of bizprocdesigner.template.validate: the verdict on the submitted graph.
 *
 * A rejected graph is data here and not a failed request, so the answer is a resource of its own rather
 * than the crud GetResponse. The typed property makes the documentation reference the dto schema.
 */
final class ValidateResponse extends Response
{
	public function __construct(public ValidationReportDto $report)
	{
	}
}
