<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Request;

use Bitrix\Rest\V3\Interaction\Request\IdRequest;

// Archiving takes an id and nothing else, so the canonical IdRequest describes it exactly. The class
// stays as its own type because the action is marked idempotent through it.
class ArchiveCollectionRequest extends IdRequest
{
}
