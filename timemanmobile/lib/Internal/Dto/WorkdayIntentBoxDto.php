<?php

namespace Bitrix\TimemanMobile\Internal\Dto;

use Bitrix\Mobile\Dto\Dto;

class WorkdayIntentBoxDto extends Dto
{
	public bool $canOpen = false;
	public bool $isDayStartCheckInEnabled = false;
	public int $chatIdWithAiAdvice = 0;
	public string $preselectedIntentId = 'WORKDAY';

	/** @var WorkdayIntentDto[] */
	public array $intentList = [];

	/** @var ShiftDto[] */
	public array $shifts = [];

	public ?WorkdayIntentScheduleDto $schedule = null;
}
