<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * A block type the catalog of the bound template does not answer with.
 *
 * The id of a catalog.block resource is the block type, so the refusal names the type instead of the numeric
 * id the stock not-found of the core is built around. Asking for a type that is not there is a user error and
 * stays out of the exception log.
 */
final class BlockTypeNotFoundException extends RestException implements SkipWriteToLogException
{
	use RussianMessageFallback;

	protected const STATUS = \CRestServer::STATUS_NOT_FOUND;

	/**
	 * A block type is an activity code and a preset is a short name of a variant, so anything longer is not
	 * a value the catalog could have answered with - it is echoed only far enough to be recognised.
	 */
	private const MAX_ECHOED_LENGTH = 64;

	public function __construct(
		private readonly string $type,
		private readonly ?string $presetId = null,
	) {
		parent::__construct();
	}

	/**
	 * A request that named a preset is refused as the pair it asked for: the type alone may well exist, and
	 * naming only it sends the agent looking for another type instead of fixing the preset.
	 */
	protected function getMessagePhraseCode(): string
	{
		return ($this->presetId ?? '') === ''
			? 'BIZPROCDESIGNER_REST_EXCEPTION_BLOCK_TYPE_NOT_FOUND'
			: 'BIZPROCDESIGNER_REST_EXCEPTION_BLOCK_PRESET_NOT_FOUND';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#TYPE#' => self::echoed($this->type),
			'#PRESET_ID#' => self::echoed((string)$this->presetId),
		];
	}

	private static function echoed(string $value): string
	{
		return mb_strlen($value) > self::MAX_ECHOED_LENGTH
			? mb_substr($value, 0, self::MAX_ECHOED_LENGTH) . '...'
			: $value;
	}
}
