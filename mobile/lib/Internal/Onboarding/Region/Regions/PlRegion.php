<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class PlRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('11:00');
		$cfg->setMaxPortalAgeDays(12);

		$cfg->onDay(1, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('🚀 Dodaj swój zespół')
				->setText('Pierwsze kroki za Tobą – teraz czas rozwinąć skrzydła z Bitrix24! Dodaj członków zespołu i zacznijcie współpracować już teraz.')
			;
		});

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('🌐 Bitrix24 w przeglądarce')
				->setText('Idealne uzupełnienie naszej aplikacji mobilnej! Wersja web oferuje jeszcze więcej możliwości – sprzedaż, projekty, automatyzację. Sprawdź sam!')
			;
		});

		$cfg->onDay(3, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('🤖 Poznaj asystenta AI')
				->setText('Zwiększ swoją produktywność z CoPilot! Asystent AI wygeneruje pomysły, zautomatyzuje sprzedaż i nie tylko. Wypróbuj już teraz!')
			;
		});

		$cfg->onDay(4, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('📈 Odkryj Bitrix24 CRM')
				->setText('Zarządzaj leadami i transakcjami, wystawiaj faktury i przyjmuj płatności. Nasz mobilny CRM ułatwia rozwój relacji z klientami.')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('✅ Zadania pod kontrolą!')
				->setText('Usprawnij swoje działania dzięki zadaniom w Bitrix24 – twórz, przypisuj, śledź i realizuj je szybciej. Przekonaj się sam!')
			;
		});

		$cfg->onDay(6, function (DayConfig $day): void {
			$day->setType(PushType::RETURN)
				->setTitle('Bitrix24 tęskni za Tobą 😢')
				->setText('Minęły już 2 dni od Twojej ostatniej wizyty. Wpadnij, przywitaj się, sprawdź powiadomienia i rusz swoje projekty do przodu.')
			;
		});

		$cfg->onDay(7, function (DayConfig $day): void {
			$day->setType(PushType::DEMO_WEB)
				->setTitle('🔥 Aktywuj okres próbny już dziś')
				->setText('Odblokuj pełen potencjał Bitrix24 i wynieś swoją firmę na wyższy poziom.')
			;
		});

		$cfg->onDay(8, function (DayConfig $day): void {
			$day->setType(PushType::SET_NOTIFICATIONS)
				->setTitle('Tydzień za Tobą 🎊')
				->setText('Twoja firma jest na dobrej drodze! Gotowy, by działać dalej? Włącz powiadomienia — a sukces sam Cię znajdzie.')
			;
		});
	}
}
