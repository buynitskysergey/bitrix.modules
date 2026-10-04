<?php

namespace Bitrix\Rest\V3\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class NotIdempotent extends AbstractAttribute
{
}
