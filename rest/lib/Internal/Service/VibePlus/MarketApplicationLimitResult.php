<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\VibePlus;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class MarketApplicationLimitResult extends Result
{
	private const DATA_APPLICABLE = 'applicable';
	private const DATA_LIMIT = 'limit';
	private const ERROR_CODE = 'VIBE_PLUS_MARKET_APPLICATION_LIMIT_UNAVAILABLE';

	public static function applicable(int $limit): self
	{
		$result = new self();
		$result->setData([
			self::DATA_APPLICABLE => true,
			self::DATA_LIMIT => $limit,
		]);

		return $result;
	}

	public static function notApplicable(): self
	{
		$result = new self();
		$result->setData([
			self::DATA_APPLICABLE => false,
			self::DATA_LIMIT => null,
		]);

		return $result;
	}

	public static function unavailable(string $message): self
	{
		$result = new self();
		$result->addError(new Error($message, self::ERROR_CODE));

		return $result;
	}

	public function isApplicable(): bool
	{
		return $this->isSuccess() && ($this->getData()[self::DATA_APPLICABLE] ?? false) === true;
	}

	public function getLimit(): ?int
	{
		$limit = $this->getData()[self::DATA_LIMIT] ?? null;

		return is_int($limit) ? $limit : null;
	}
}
