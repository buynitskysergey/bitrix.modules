<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class TrRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('11:00');
		$cfg->setMaxPortalAgeDays(12);

		$cfg->onDay(1, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('Ekibinizi sürece dâhil edin')
				->setText('Bitrix24 ile harika bir başlangıç yaptınız! Şimdi ekip üyelerinizi ekleyin ve iş birliğine başlayın.')
			;
		});

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('Web\'de Bitrix24\'ü keşfedin')
				->setText('Mobil uygulamamızın mükemmel bir tamamlayıcısı olan web sürümü, satış, proje yönetimi ve iş akışı otomasyonu konusunda çok daha fazlasını sunar.')
			;
		});

		$cfg->onDay(3, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('🤖 YZ asistanınızla tanışın')
				->setText('CoPilot ile verimliliği artırın! Akıllı yapay zekâ asistanımız, fikir üretmenize, mesaj taslakları oluşturmanıza, satışları otomatikleştirmenize ve daha fazlasına yardımcı olur. Hemen deneyin!')
			;
		});

		$cfg->onDay(4, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('📈 Bitrix24 CRM\'i keşfedin')
				->setText('Potansiyel müşterilerinizi ve anlaşmalarınızı takip edin, faturalar oluşturun ve ödemeleri kolayca alın. Mobil CRM\'imiz, ilişkilerinizi zahmetsizce takip etmenize, yönetmenize ve büyütmenize yardımcı olur.')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('✅ Görevleri ustaca yönetin')
				->setText('Bitrix24 ile iş akışlarınızı kolaylaştırın – görev oluşturun, atayın, takip edin ve işleri daha hızlı tamamlayın. Hemen deneyin!')
			;
		});
	}
}
