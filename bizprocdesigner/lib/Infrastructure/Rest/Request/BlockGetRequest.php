<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Request;

use Bitrix\Rest\V3\Interaction\Request\GetRequest;

/**
 * Reading one block of the agent-facing catalog.
 *
 * The resource id is the block type. A preset narrows the read to that preset of the same block:
 * the answer then also carries the property values the preset applies.
 */
final class BlockGetRequest extends GetRequest
{
	public ?string $presetId = null;
}
