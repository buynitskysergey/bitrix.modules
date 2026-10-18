<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

/**
 * Single landing block code -> markdown converter.
 * Implementations are stateless and idempotent.
 */
interface Converter
{
	/**
	 * @param array{ID:int, LID:int, CODE:string, CONTENT:string, ANCHOR:?string, SORT:int, DESIGNED:string, SOURCE_PARAMS:?array} $block
	 */
	public function convert(array $block): string;
}
