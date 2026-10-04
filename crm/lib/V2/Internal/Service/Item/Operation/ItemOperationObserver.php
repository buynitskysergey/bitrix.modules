<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Operation;

use Bitrix\Crm\Item as LegacyItem;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class ItemOperationObserver
{
	private bool $hasEnteredLaunch = false;
	private ?LegacyItem $legacyItem = null;
	private ?Result $result = null;
	private ?\Throwable $throwable = null;

	public function onBeforeLaunch(LegacyItem $legacyItem): void
	{
		$this->hasEnteredLaunch = true;
		$this->legacyItem = $legacyItem;
		$this->result = null;
		$this->throwable = null;
	}

	public function onAfterLaunch(LegacyItem $legacyItem, Result $result): void
	{
		$this->legacyItem = $legacyItem;
		$this->result = $result;
		$this->throwable = null;
	}

	public function onThrowable(?LegacyItem $legacyItem, \Throwable $throwable): void
	{
		$this->legacyItem = $legacyItem;
		$this->throwable = $throwable;
	}

	public function onPreparationThrowable(?LegacyItem $legacyItem, \Throwable $throwable): void
	{
		$this->hasEnteredLaunch = false;
		$this->legacyItem = $legacyItem;
		$this->result = null;
		$this->throwable = $throwable;
	}

	public function hasEnteredLaunch(): bool
	{
		return $this->hasEnteredLaunch;
	}

	public function getLegacyItem(): ?LegacyItem
	{
		return $this->legacyItem;
	}

	public function getItemId(): ?int
	{
		$itemId = $this->legacyItem?->getId();

		return $itemId > 0 ? $itemId : null;
	}

	public function getResult(): ?Result
	{
		return $this->result;
	}

	public function getThrowable(): ?\Throwable
	{
		return $this->throwable;
	}
}
