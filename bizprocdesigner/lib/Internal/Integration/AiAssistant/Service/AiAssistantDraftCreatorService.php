<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\Draft;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\BizprocDesignerPullManager;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\Enum\BizprocDesignerPullEvent;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\ObjectNotFoundException;
use Psr\Container\NotFoundExceptionInterface;

final readonly class AiAssistantDraftCreatorService
{
	private BizprocDesignerPullManager $pullManager;

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public function __construct(?BizprocDesignerPullManager $pullManager = null)
	{
		$this->pullManager = $pullManager ?? Container::getPullManager();
	}

	public function pushDraft(Draft $draft): bool
	{
		if (!$draft->userId)
		{
			return false;
		}

		return $this->pullManager->sendEvent(
			$draft->userId,
			BizprocDesignerPullEvent::AiDraftUpdated,
			$this->toFrontendPayload($draft),
		);
	}

	/**
	 * Готовит payload черновика для pull в той же frontend-схеме, что отдаёт
	 * штатная загрузка редактора (Diagram.get). Отличается только форма портов:
	 * доменный PortCollection::toArray группирует порты в объект { input, output },
	 * а редактор везде ждёт плоский массив портов с полем type (как bizproc
	 * NodePorts::toArray). Нормализуем в единственной точке отправки pull, не
	 * затрагивая общий Block::toArray — его используют REST-каталог и round-trip.
	 */
	private function toFrontendPayload(Draft $draft): array
	{
		$payload = $draft->toArray();
		$payload['blocks'] = array_map(
			static function (array $block): array {
				$block['ports'] = self::flattenPorts((array)($block['ports'] ?? []));

				return $block;
			},
			(array)($payload['blocks'] ?? []),
		);

		return $payload;
	}

	/**
	 * Разворачивает порты из формы { <type>: [port, ...] } в плоский массив
	 * [{ ...port, type }, ...]. Уже плоский список возвращается как есть.
	 *
	 * @param array $ports
	 * @return array<int, array>
	 */
	private static function flattenPorts(array $ports): array
	{
		if (array_is_list($ports))
		{
			return $ports;
		}

		$flat = [];
		foreach ($ports as $type => $portList)
		{
			if (!is_array($portList))
			{
				continue;
			}

			foreach ($portList as $port)
			{
				if (is_array($port))
				{
					$port['type'] ??= $type;
					$flat[] = $port;
				}
			}
		}

		return $flat;
	}
}
