<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class VnRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('11:00');
		$cfg->setMaxPortalAgeDays(12);

		$cfg->onDay(1, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('👥 Mời đội nhóm của bạn')
				->setText('Bạn có khởi đầu tuyệt vời với Bitrix24! Chỉ cần mời đồng nghiệp của bạn ngay bây giờ và bắt đầu cộng tác.')
			;
		});

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('Khám phá Bitrix24 trên web')
				->setText('Phiên bản web Bitrix24 là đồng hành hoàn hảo của app vì cung cấp nhiều tính năng hơn để tối ưu bán hàng, quản lý dự án và tự động hóa quy trình làm việc.')
			;
		});

		$cfg->onDay(3, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('🤖 Gặp trợ lý AI của bạn')
				->setText('Tăng năng suất với trợ lý AI CoPilot: sáng tạo ý tưởng, soạn thảo tin nhắn, tự động hóa bán hàng và hơn thế nữa. Hãy dùng thử ngay!')
			;
		});

		$cfg->onDay(4, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('📈 Khám phá Bitrix24 CRM')
				->setText('Quản lý khách hàng tiềm năng và giao dịch, xuất hóa đơn và nhận thanh toán mọi lúc, mọi nơi. Dễ dàng duy trì và phát triển mối quan hệ với khách hàng bằng CRM di động.')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('Chinh phục tác vụ như pro')
				->setText('Tối ưu quy trình làm việc với Bitrix24 – tạo và chỉ định tác vụ, giám sát tiến độ và hoàn thành công việc nhanh hơn. Hãy thử xem!')
			;
		});
	}
}
