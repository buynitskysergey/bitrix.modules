<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Response;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\ConnectInstructionDto;
use Bitrix\Rest\V3\Interaction\Response\Response;

/**
 * Response of bizprocdesigner.agent.connect: the onboarding brief of the bound template.
 *
 * The brief is built by a service rather than read as a stored resource, so the contour answers with
 * its own response instead of the crud GetResponse. The typed property keeps the envelope of a
 * single-resource read (result.item) and makes the documentation reference the dto schema.
 */
final class ConnectResponse extends Response
{
	public function __construct(public ConnectInstructionDto $item)
	{
	}
}
