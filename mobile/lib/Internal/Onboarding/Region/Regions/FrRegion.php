<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class FrRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('11:00');
		$cfg->setMaxPortalAgeDays(12);

		$cfg->onDay(1, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('Rassemblez votre équipe')
				->setText('Vos débuts avec Bitrix24 ont été excellents! Ajoutez vos collègues et commencez à collaborer.')
			;
		});

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('Explorez Bitrix24 sur Web')
				->setText('Notre version Web, parfaite avec l\'appli mobile, offre plus de fonctions pour ventes, gestion de projets et automatisation des tâches.')
			;
		});

		$cfg->onDay(3, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('Découvrez l\'assistant IA')
				->setText('CoPilot, notre assistant IA, booste votre productivité : idées, messages, ventes automatisées et plus. Testez-le dès aujourd\'hui!')
			;
		});

		$cfg->onDay(4, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('Découvrez le CRM Bitrix24')
				->setText('Gérez prospects, factures et paiements en mobilité. Notre CRM facilite le suivi et la croissance de vos relations clients où que vous soyez.')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('Gérez vos tâches en pro')
				->setText('Simplifiez vos flux de travail avec Bitrix24 : créez, assignez, suivez et accomplissez vos tâches plus rapidement. Essayez dès maintenant !')
			;
		});
	}
}
