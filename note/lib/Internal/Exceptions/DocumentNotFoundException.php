<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Exceptions;

use Bitrix\Note\Public\Exceptions\ObjectUnavailableInterface;

final class DocumentNotFoundException extends \RuntimeException implements ObjectUnavailableInterface
{
}
