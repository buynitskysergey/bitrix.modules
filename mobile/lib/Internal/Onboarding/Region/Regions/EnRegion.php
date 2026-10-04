<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class EnRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('11:00');
		$cfg->setMaxPortalAgeDays(12);

		$cfg->onDay(1, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle("Let's get the team onboard")
				->setText("You're off to a great start with Bitrix24! Just add your team members now and start collaborating.")
			;
		});

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('Explore Bitrix24 on the web')
				->setText('A perfect companion to our mobile app, the web version offers even more in terms of sales, project management, and workflow automation.')
			;
		});

		$cfg->onDay(3, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('🤖 Meet your AI assistant')
				->setText('Boost productivity with CoPilot, our smart AI assistant that helps you generate ideas, draft messages, automate sales, and more. Try it now!')
			;
		});

		$cfg->onDay(4, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('📈 Discover Bitrix24 CRM')
				->setText('Stay on top of your leads and deals, generate invoices and receive payments on the go. Our mobile CRM helps you track, manage, and grow relationships – effortlessly.')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('✅ Conquer tasks like a pro')
				->setText('Streamline your workflows with tasks in Bitrix24 – create, assign, track, and get things done faster. Give it a go!')
			;
		});

		$cfg->onDay(6, function (DayConfig $day): void {
			$day->setType(PushType::RETURN)
				->setTitle("Bitrix24's been lonely 😢")
				->setText('It\'s been 2 days since you last checked in! Come say hello, clear your notifications, and keep your projects moving.')
			;
		});

		$cfg->onDay(7, function (DayConfig $day): void {
			$day->setType(PushType::DEMO_WEB)
				->setTitle('🔥 Try free trial today on the web')
				->setText('Unlock full power of Bitrix24 and take your business to the next level')
			;
		});

		$cfg->onDay(8, function (DayConfig $day): void {
			$day->setType(PushType::SET_NOTIFICATIONS)
				->setTitle('The week is behind you 🎊')
				->setText("You're business on the right track. Ready to keep going? Set your notifications — and success will follow.")
			;
		});
	}
}
