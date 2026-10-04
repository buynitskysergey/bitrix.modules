<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class ItRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('11:00');
		$cfg->setMaxPortalAgeDays(12);

		$cfg->onDay(1, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('👥 Aggiungi il tuo team')
				->setText('Hai già iniziato alla grande con Bitrix24! Aggiungi subito i membri del tuo team per iniziare a collaborare.')
			;
		});

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('🌐 Bitrix24 sul tuo browser')
				->setText('La versione web è l\'ideale per completare la nostra app mobile e offre ancora di più in termini di vendite, project management e automazione del flusso di lavoro.')
			;
		});

		$cfg->onDay(3, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('🤖 Conosci l\'assistente IA')
				->setText('Aumenta la tua produttività con CoPilot, l\'assistente IA: idee, messaggi, vendite automatizzate e molto altro. Provalo subito!')
			;
		});

		$cfg->onDay(4, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('📈 Scopri Bitrix24 CRM')
				->setText('Gestisci lead, fatture e pagamenti ovunque ti trovi. Il nostro CRM mobile semplifica il monitoraggio delle relazioni con i clienti.')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('✅ Incarichi sotto controllo')
				->setText('Semplifica i tuoi flussi di lavoro con gli incarichi di Bitrix24: crea, assegna, monitora e completa tutto più velocemente. Provalo!')
			;
		});

		$cfg->onDay(6, function (DayConfig $day): void {
			$day->setType(PushType::RETURN)
				->setTitle('Bitrix24 sente la tua mancanza 😢')
				->setText('Sono passati 2 giorni dall\'ultima volta che hai effettuato l\'accesso! Vieni a salutarci, visualizza le notifiche e porta avanti i tuoi progetti.')
			;
		});

		$cfg->onDay(7, function (DayConfig $day): void {
			$day->setType(PushType::DEMO_WEB)
				->setTitle('🔥 Attiva subito la prova gratuita sul tuo browser')
				->setText('Sfrutta tutta la potenza di Bitrix24 e porta la tua attività al livello successivo.')
			;
		});

		$cfg->onDay(8, function (DayConfig $day): void {
			$day->setType(PushType::SET_NOTIFICATIONS)
				->setTitle('Una settimana è andata 🎊')
				->setText('La tua attività è sulla giusta strada. Non vedi l\'ora di continuare? Attiva le notifiche e il successo non tarderà ad arrivare.')
			;
		});
	}
}
