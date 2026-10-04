<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class JpRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('11:00');
		$cfg->setMaxPortalAgeDays(12);

		$cfg->onDay(1, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('チームを招待しましょう')
				->setText('Bitrix24のご利用ありがとうございます。次はチームメンバーを招待して、共同作業を始めましょう！')
			;
		});

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('Bitrix24のWeb版もぜひお試しください！')
				->setText('モバイルアプリとあわせて使えるWeb版では、営業管理・プロジェクト管理・業務自動化をさらに強化できます。今すぐチェック！')
			;
		});

		$cfg->onDay(3, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('🤖 AIアシスタント、はじめませんか？')
				->setText('CoPilotは、アイデア出しやメッセージ作成、営業の自動化まで、仕事のあらゆる場面でサポートするスマートなAIアシスタントです。ぜひ一度お試しください。')
			;
		});

		$cfg->onDay(4, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('📈 Bitrix24 CRMを使ってみませんか？')
				->setText('外出先でもリードや取引の管理、請求書の作成、入金確認がカンタンにできます。いつでもどこでもビジネスをサポートします。')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('✅ プロのようなタスク管理を')
				->setText('Bitrix24でタスクを作成・割り当て・管理して、効率よく仕事を進めましょう。ぜひ試してみてください！')
			;
		});
	}
}
