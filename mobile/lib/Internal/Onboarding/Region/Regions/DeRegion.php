<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class DeRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('11:00');
		$cfg->setMaxPortalAgeDays(12);

		$cfg->onDay(1, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('👥 Laden Sie Ihr Team ein')
				->setText('Mit Bitrix24 haben Sie bereits einen guten Start hingelegt! Fügen Sie jetzt Ihre Kollegen hinzu und beginnen Sie gemeinsam zu arbeiten.')
			;
		});

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('Bitrix24 in Ihrem Browser')
				->setText('Die Webversion ist eine perfekte Ergänzung zu unserer mobilen App und bietet noch mehr Tools für Vertrieb, Projektmanagement und Automatisierung.')
			;
		});

		$cfg->onDay(3, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('KI-Assistent ist für Sie da')
				->setText('Steigern Sie Ihre Produktivität mit CoPilot – Ihrem KI-Assistenten für bessere Ideen, Texte und Vertriebsautomatisierung. Jetzt testen!')
			;
		});

		$cfg->onDay(4, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('Entdecken Sie Bitrix24 CRM')
				->setText('Verfolgen Sie Ihre Leads und Aufträge, erstellen Sie Rechnungen und empfangen Sie Zahlungen – direkt in unserem mobilen CRM.')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('✅ Aufgaben fest im Griff')
				->setText('Optimieren Sie Ihre Arbeitsabläufe mit Bitrix24: Erstellen, verteilen, verfolgen und erledigen Sie Ihre Aufgaben schneller. Probieren Sie es aus!')
			;
		});
	}
}
