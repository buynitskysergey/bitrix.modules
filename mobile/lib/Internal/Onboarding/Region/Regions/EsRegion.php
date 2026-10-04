<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class EsRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('11:00');
		$cfg->setMaxPortalAgeDays(12);

		$cfg->onDay(1, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('👥 Invite a su equipo')
				->setText('¡Ha comenzado muy bien con Bitrix24! Haga que su equipo se una al bordo ahora y empiece a colaborar.')
			;
		});

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('Explore Bitrix24 en la web')
				->setText('Complemento perfecto de nuestra app móvil, la web ofrece más en ventas, gestión y automatización del flujo de trabajo.')
			;
		});

		$cfg->onDay(3, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('Conozca al asistente de IA')
				->setText('Impulse su productividad con CoPilot, nuestro asistente de IA que te ayudará a generar ideas y mensajes, automatizar ventas y más. ¡Pruebe ahora!')
			;
		});

		$cfg->onDay(4, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('📈 Descubra Bitrix24 CRM')
				->setText('Controle prospectos, facturas y pagos donde sea. Nuestro CRM móvil facilita relaciones — sin esfuerzo.')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('✅ Haga tareas como un "pro"')
				->setText('Optimice sus flujos de trabajo con tareas en Bitrix24: cree, asigne, controle y haga las cosas más rápido. ¡Pruébelo!')
			;
		});
	}
}
