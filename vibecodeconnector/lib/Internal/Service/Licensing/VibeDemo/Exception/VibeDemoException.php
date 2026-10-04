<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception;

use Bitrix\Main\Localization\Loc;
use Bitrix\Vibecodeconnector\Internal\Exception\CodedSystemException;

abstract class VibeDemoException extends CodedSystemException
{
	protected const MESSAGE_ID = '';
	public const ERROR_CODE = '';

	public function __construct(?\Throwable $previous = null)
	{
		Loc::loadMessages(__FILE__);
		parent::__construct((string)Loc::getMessage(static::MESSAGE_ID), static::ERROR_CODE, $previous);
	}

	public function getErrorCode(): string
	{
		return parent::getErrorCode();
	}
}
