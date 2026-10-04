<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Integration\UI\EntitySelector\CustomTemplate;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\MessageService\Public\Dto\CustomTemplate\CustomTemplateDetails;
use Bitrix\MessageService\Public\Dto\CustomTemplate\CustomTemplateSelectorItem;
use Bitrix\MessageService\Public\Provider\CustomTemplate\CustomTemplateProvider as CustomTemplateProviderService;
use Bitrix\MessageService\Public\Service\CustomTemplate\CustomTemplateZoneRegistry;
use Bitrix\MessageService\Public\Type\CustomTemplate\SelectorBadge;
use Bitrix\MessageService\Public\Type\CustomTemplate\TemplateBinding;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;

final class CustomTemplateProvider extends BaseProvider
{
	public const ENTITY_ID = 'messageservice-custom-template';

	public function __construct(array $options = [])
	{
		parent::__construct();
		$this->options = $options;
	}

	public function isAvailable(): bool
	{
		if (!Loader::includeModule('ui') || !Loader::includeModule('messageservice'))
		{
			return false;
		}

		return $this->getReadableBinding() !== null;
	}

	public function fillDialog(Dialog $dialog): void
	{
		$binding = $this->getReadableBinding();
		if ($binding === null)
		{
			return;
		}

		$providerSvc = ServiceLocator::getInstance()->get(CustomTemplateProviderService::class);
		foreach ($providerSvc->getForSelector($binding, 10) as $dto)
		{
			$dialog->addRecentItem($this->makeItem($dto));
		}
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$query = trim($searchQuery->getQuery());
		if ($query === '')
		{
			return;
		}

		$binding = $this->getReadableBinding();
		if ($binding === null)
		{
			return;
		}

		$providerSvc = ServiceLocator::getInstance()->get(CustomTemplateProviderService::class);
		foreach ($providerSvc->getForSelector($binding, 10, $query) as $dto)
		{
			$dialog->addItem($this->makeItem($dto));
		}
	}

	public function getItems(array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$currentBinding = $this->getReadableBinding();
		if ($currentBinding === null)
		{
			return [];
		}

		$intIds = array_map('intval', $ids);
		$providerSvc = ServiceLocator::getInstance()->get(CustomTemplateProviderService::class);

		// One DB query for the full id set instead of N getById() round-trips.
		$dtos = $providerSvc->getByIds($intIds);

		$items = [];
		// Preserve caller-supplied id order — entity-selector consumers (BX.UI.Selector etc.)
		// rely on ordered output to keep their dialog state stable.
		foreach ($intIds as $id)
		{
			$dto = $dtos[$id] ?? null;
			if ($dto === null || !$this->isCurrentBindingTemplate($dto, $currentBinding))
			{
				continue;
			}
			$items[] = new Item([
				'id' => $dto->id,
				'entityId' => self::ENTITY_ID,
				'title' => $dto->title,
				'customData' => [
					'body' => $dto->body,
					'bodyPreview' => '',
					'isForeign' => false,
				],
			]);
		}

		return $items;
	}

	private function makeItem(CustomTemplateSelectorItem $dto): Item
	{
		return new Item([
			'id' => $dto->id,
			'entityId' => self::ENTITY_ID,
			'title' => $dto->title,
			'subtitle' => $dto->bodyPreview,
			'badges' => array_map(static fn(SelectorBadge $badge): array => $badge->toArray(), $dto->badges),
			'customData' => [
				'body' => $dto->body,
				'bodyPreview' => $dto->bodyPreview,
				'isForeign' => $dto->isForeign,
			],
		]);
	}

	private function getReadableBinding(): ?TemplateBinding
	{
		$binding = $this->getCurrentBinding();
		if ($binding === null)
		{
			return null;
		}

		$zone = ServiceLocator::getInstance()
			->get(CustomTemplateZoneRegistry::class)
			->get($binding->zone)
		;
		if ($zone === null)
		{
			return null;
		}

		return $zone->canReadTemplates((int)CurrentUser::get()->getId(), $binding) ? $binding : null;
	}

	private function getCurrentBinding(): ?TemplateBinding
	{
		$options = $this->getOptions();
		$zoneId = (string)($options['zoneId'] ?? '');
		$sceneId = (string)($options['sceneId'] ?? '');
		$targetId = (string)($options['targetId'] ?? '');
		if ($zoneId === '' || $sceneId === '' || $targetId === '')
		{
			return null;
		}

		return new TemplateBinding($zoneId, $sceneId, $targetId);
	}

	private function isCurrentBindingTemplate(CustomTemplateDetails $dto, TemplateBinding $currentBinding): bool
	{
		return $dto->zoneId === $currentBinding->zone
			&& $dto->sceneId === $currentBinding->scene
			&& $dto->targetId === $currentBinding->targetId;
	}
}
