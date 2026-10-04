<?php

namespace Bitrix\Crm\Ads\Pixel\ConversionEventTriggers\Vk;

use Bitrix\Crm\Ads\Pixel\EventBuilders\CrmConversionEventBuilderInterface;
use Bitrix\Crm\Ads\Pixel\EventBuilders\Vk\LeadEventBuilder;
use Bitrix\Crm\LeadTable;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Settings\Mode;

class LeadTrigger extends BaseTrigger
{
	private const EVENT_NAME = 'LEAD';

	private const EVENT_STATUS = 'QUALIFIED';

	protected array $lead;

	private LeadEventBuilder $eventBuilder;

	private SignalCooldown $signalCooldown;

	public function __construct(int $leadId)
	{
		$this->lead = $this->loadLead($leadId);
		$this->eventBuilder = new LeadEventBuilder($this->lead);
		$this->signalCooldown = new SignalCooldown();

		parent::__construct();
	}

	public static function onLeadStageChange(array $lead): void
	{
		if (!isset($lead['ID'], $lead['STATUS_ID']))
		{
			return;
		}

		static::executeSafely((int)$lead['ID']);
	}

	public static function onWebFormFilled(int $leadId): void
	{
		if ($leadId <= 0)
		{
			return;
		}

		try
		{
			(new SignalCooldown())->reset(
				\CCrmOwnerType::Lead,
				$leadId,
				self::EVENT_NAME,
				self::EVENT_STATUS,
			);

			(new static($leadId))->execute();
		}
		catch (\Throwable $exception)
		{
			static::logHandlerFailure('lead', $leadId, $exception);
		}
	}

	private static function executeSafely(int $leadId): void
	{
		try
		{
			(new static($leadId))->execute();
		}
		catch (\Throwable $exception)
		{
			static::logHandlerFailure('lead', $leadId, $exception);
		}
	}

	protected function checkTarget(): bool
	{
		return $this->getCrmMode() !== Mode::SIMPLE
			&& ($this->lead['STATUS_SEMANTIC_ID'] ?? null) === PhaseSemantics::SUCCESS;
	}

	protected function allowByCooldown(): bool
	{
		$leadId = (int)($this->lead['ID'] ?? 0);
		if ($leadId <= 0)
		{
			return false;
		}

		return $this->signalCooldown->allowByCooldown(
			\CCrmOwnerType::Lead,
			$leadId,
			(string)($this->lead['STATUS_SEMANTIC_ID'] ?? ''),
			PhaseSemantics::SUCCESS,
			self::EVENT_NAME,
			self::EVENT_STATUS,
		);
	}

	protected function getConversionEventBuilder(): CrmConversionEventBuilderInterface
	{
		return $this->eventBuilder;
	}

	protected function getCrmMode(): int
	{
		return Mode::getCurrent();
	}

	protected function loadLead(int $leadId): array
	{
		return LeadTable::query()
			->setSelect([
				'ID',
				'STATUS_ID',
				'STATUS_SEMANTIC_ID',
			])
			->where('ID', $leadId)
			->setLimit(1)
			->fetch()
			?: [];
	}
}
