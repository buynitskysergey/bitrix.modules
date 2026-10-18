<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Request;

use Bitrix\Main\Validation\Rule\AtLeastOnePropertyNotEmpty;
use Bitrix\Main\Validation\Rule\OnlyOneOfPropertyRequired;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;

// Canonical UpdateRequest (id + fields) plus the `overwrite` write-control flag,
// which is not a document field and therefore has no place inside `fields`.
// Nullable so the doc-schema generator keeps it optional (it marks any non-nullable
// property required, ignoring the default); runtime still falls back to false.
//
// The two rules below are repeated from the parent on purpose: class-level validation attributes are
// read off the concrete class only, so a subclass silently drops the rule that keeps a request without
// an id from reaching the action.
#[AtLeastOnePropertyNotEmpty(propertyNames: ['id', 'filter'], showPropertyNames: true)]
#[OnlyOneOfPropertyRequired(propertyNames: ['id', 'filter'])]
class UpdateDocumentRequest extends UpdateRequest
{
	public ?bool $overwrite = false;
}
