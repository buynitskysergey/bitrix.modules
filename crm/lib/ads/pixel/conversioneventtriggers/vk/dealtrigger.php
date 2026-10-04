<?php

namespace Bitrix\Crm\Ads\Pixel\ConversionEventTriggers\Vk;

use Bitrix\Crm\Ads\Pixel\EventBuilders\CrmConversionEventBuilderInterface;
use Bitrix\Crm\Ads\Pixel\EventBuilders\Vk\DealEventBuilder;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\PhaseSemantics;

class DealTrigger extends BaseTrigger
{
	private const EVENT_NAME = 'SALE';

	private const EVENT_STATUS = 'WON';

	protected array $deal;

	private DealEventBuilder $eventBuilder;

	private SignalCooldown $signalCooldown;

	public function __construct(int $dealId)
	{
		$this->deal = $this->loadDeal($dealId);
		$this->eventBuilder = new DealEventBuilder($this->deal);
		$this->signalCooldown = new SignalCooldown();

		parent::__construct();
	}

	public static function onDealChangeStage(array $deal): void
	{
		if (!isset($deal['ID'], $deal['STAGE_ID']))
		{
			return;
		}

		static::executeSafely((int)$deal['ID']);
	}

	public static function onWebFormFilled(int $dealId): void
	{
		if ($dealId <= 0)
		{
			return;
		}

		try
		{
			(new SignalCooldown())->reset(
				\CCrmOwnerType::Deal,
				$dealId,
				self::EVENT_NAME,
				self::EVENT_STATUS,
			);

			(new static($dealId))->execute();
		}
		catch (\Throwable $exception)
		{
			static::logHandlerFailure('deal', $dealId, $exception);
		}
	}

	private static function executeSafely(int $dealId): void
	{
		try
		{
			(new static($dealId))->execute();
		}
		catch (\Throwable $exception)
		{
			static::logHandlerFailure('deal', $dealId, $exception);
		}
	}

	protected function checkTarget(): bool
	{
		return ($this->deal['STAGE_SEMANTIC_ID'] ?? null) === PhaseSemantics::SUCCESS;
	}

	protected function allowByCooldown(): bool
	{
		$dealId = (int)($this->deal['ID'] ?? 0);
		if ($dealId <= 0)
		{
			return false;
		}

		return $this->signalCooldown->allowByCooldown(
			\CCrmOwnerType::Deal,
			$dealId,
			(string)($this->deal['STAGE_SEMANTIC_ID'] ?? ''),
			PhaseSemantics::SUCCESS,
			self::EVENT_NAME,
			self::EVENT_STATUS,
		);
	}

	protected function getConversionEventBuilder(): CrmConversionEventBuilderInterface
	{
		return $this->eventBuilder;
	}

	protected function loadDeal(int $dealId): array
	{
		return DealTable::query()
			->setSelect([
				'ID',
				'CATEGORY_ID',
				'STAGE_ID',
				'STAGE_SEMANTIC_ID',
				'OPPORTUNITY',
				'CURRENCY_ID',
				'LEAD_ID',
			])
			->where('ID', $dealId)
			->setLimit(1)
			->fetch()
			?: [];
	}
}
