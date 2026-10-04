<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region\Regions;

use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Region\DayConfig;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class BrRegion
{
	public function __invoke(RegionConfig $cfg): void
	{
		$cfg->setTimeSend('11:00');
		$cfg->setMaxPortalAgeDays(12);

		$cfg->onDay(1, function (DayConfig $day): void {
			$day->setType(PushType::INVITE)
				->setTitle('Adicione sua equipe!')
				->setText('Você começou muito bem com o Bitrix24! Agora é só adicionar os membros da sua equipe e começar a colaborar.')
			;
		});

		$cfg->onDay(2, function (DayConfig $day): void {
			$day->setType(PushType::DESKTOP)
				->setTitle('Explore o Bitrix24 web')
				->setText('Companheiro ideal do app mobile, a versão web oferece ainda mais recursos de vendas, gestão de projetos e automação de fluxos de trabalho.')
			;
		});

		$cfg->onDay(3, function (DayConfig $day): void {
			$day->setType(PushType::COPILOT)
				->setTitle('Conheça o CoPilot')
				->setText('Experimente um aumento de produtividade com o CoPilot, seu assistente de IA que te ajuda com ideias, mensagens, automações, vendas, e muito mais!')
			;
		});

		$cfg->onDay(4, function (DayConfig $day): void {
			$day->setType(PushType::CRM)
				->setTitle('Venda mais com o CRM!')
				->setText('Fique no controle dos seus leads, gere faturas e receba pagamentos de onde estiver com o CRM mobile Bitrix24!')
			;
		});

		$cfg->onDay(5, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('Domine suas tarefas')
				->setText('Organize seus fluxos de trabalho com as tarefas no Bitrix24: crie, delegue, acompanhe e realize tudo mais rápido. Teste agora!')
			;
		});

		$cfg->onDay(6, function (DayConfig $day): void {
			$day->setType(PushType::TASK)
				->setTitle('Segunda-feira?')
				->setText('Está na hora: 🔅');
		});
	}
}
