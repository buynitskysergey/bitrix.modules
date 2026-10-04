<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Verdict of validating an agent-facing workflow graph (DTO-02).
 *
 * The verdict covers both the domain validation and the post-layout frame check, because dry-run
 * validating and pushing a draft are required to answer with the same one.
 *
 * The report is built from a domain Result by {@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Service\GraphValidationErrorMapper},
 * not by a dto mapper of its own: the whole resource is read-only.
 */
class ValidationReportDto extends Dto
{
	#[Description('The submitted graph passed both the domain validation and the post-layout check.')]
	public bool $valid;

	#[ElementType(ValidationIssueDto::class)]
	#[Description('Problems found in the submitted graph. Empty when the graph is valid.')]
	public DtoCollection $issues;
}
