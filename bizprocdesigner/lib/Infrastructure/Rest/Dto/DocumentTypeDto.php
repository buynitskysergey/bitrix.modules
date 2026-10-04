<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * Bizproc document type of a template: the transport shape of DocumentDescription.
 *
 * Read-only for the agent - the document type follows the template the token is bound to.
 */
class DocumentTypeDto extends Dto
{
	public string $module;

	public string $entity;

	public string $documentType;
}
