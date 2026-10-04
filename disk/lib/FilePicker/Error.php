<?php
declare(strict_types=1);

namespace Bitrix\Disk\FilePicker;

use Bitrix\Disk\Internals\Error\Error as DiskError;

final class Error extends DiskError
{
	public const INVALID_CONTEXT = 'invalid_context';
	public const INVALID_FILTER = 'invalid_filter';
	public const INVALID_ORDER = 'invalid_order';
	public const INVALID_QUERY = 'invalid_query';
	public const NOT_FOUND = 'not_found';
	public const TOTAL_UNAVAILABLE = 'total_unavailable';
	public const TOO_MANY_ITEMS = 'too_many_items';
	public const NOT_SELECTABLE = 'not_selectable';

	public static function create(string $code, string $message = ''): self
	{
		return new self($message !== '' ? $message : $code, $code);
	}
}
