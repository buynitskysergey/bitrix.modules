<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Onboarding brief of the external agent contour: the markdown instruction telling an agent how to
 * read and edit the template its token is bound to.
 *
 * The brief is built by a service, not read from a store, so the action fills the dto itself and the
 * resource needs no mapper.
 */
class ConnectInstructionDto extends Dto
{
	#[Description('Template the token is bound to - the brief describes this template only.')]
	public int $templateId;

	#[Description('Markdown brief for an external agent.')]
	public string $instruction;
}
