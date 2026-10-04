<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class CnRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('11:00');
		$cfg->setMaxPortalAgeDays(12);

		$cfg->onDay(1, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('邀請團隊加入吧')
				->setText('您已經順利開始使用Bitrix24！現在只需新增團隊成員，即可開始協作。')
			;
		});

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('在網頁版探索Bitrix24')
				->setText('作為我們行動應用程式的完美搭檔，網頁版在銷售、專案管理和工作流程自動化方面提供更多功能。')
			;
		});

		$cfg->onDay(4, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('📈 探索Bitrix24 CRM')
				->setText('隨時掌握潛在客戶與交易、開立發票並接收付款。我們的行動CRM協助您輕鬆追蹤、管理並深化客戶關係。')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('✅ 像專業人士一樣完成任務')
				->setText('透過Bitrix24任務功能簡化您的工作流程——建立、指派、追蹤並更快完成任務。立即去試用吧！')
			;
		});
	}
}
