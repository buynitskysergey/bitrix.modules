<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Infrastructure\Controller\CustomTemplate;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\MessageService\Public\Command\CustomTemplate\CreateCustomTemplateCommand;
use Bitrix\MessageService\Public\Command\CustomTemplate\DeleteCustomTemplateCommand;
use Bitrix\MessageService\Public\Command\CustomTemplate\OnTitleDuplicate;
use Bitrix\MessageService\Public\Command\CustomTemplate\UpdateCustomTemplateCommand;
use Bitrix\MessageService\Public\Dto\CustomTemplate\CustomTemplateDetails;
use Bitrix\MessageService\Public\Provider\CustomTemplate\CustomTemplateProvider;
use Bitrix\MessageService\Public\Provider\CustomTemplate\Zone\AbstractZoneProvider;
use Bitrix\MessageService\Public\Service\CustomTemplate\CustomTemplateZoneRegistry;
use Bitrix\MessageService\Public\Type\CustomTemplate\CustomTemplate as TemplateValue;
use Bitrix\MessageService\Public\Type\CustomTemplate\TemplateBinding;

final class CustomTemplate extends JsonController
{
	public function createAction(
		CurrentUser $currentUser,
		string $zone,
		string $scene,
		string $targetId,
		string $title,
		string $body,
		string $strategy = 'reject',
	): ?CustomTemplateDetails
	{
		$userId = (int)$currentUser->getId();
		$binding = new TemplateBinding($zone, $scene, $targetId);

		$zoneProvider = $this->getRegistry()->get($binding->zone);
		if ($zoneProvider === null)
		{
			$this->addError(new Error('Unknown zone: ' . $binding->zone, 'ZONE_NOT_REGISTERED'));

			return null;
		}
		// Gate the raw scene only on create-input; reads/deletes re-derive it from storage.
		if (!$zoneProvider->isValidScene($binding->scene))
		{
			$this->addError(new Error('Unknown scene: ' . $binding->scene, 'INVALID_SCENE'));

			return null;
		}
		if (!$zoneProvider->canAddTemplate($userId, $binding))
		{
			$this->addAccessDeniedError();

			return null;
		}

		$strategyEnum = OnTitleDuplicate::tryFrom($strategy);
		if ($strategyEnum === null)
		{
			$this->addError(new Error('Unknown strategy: ' . $strategy, 'INVALID_STRATEGY'));

			return null;
		}

		$command = new CreateCustomTemplateCommand(
			binding: $binding,
			template: new TemplateValue($title, $body),
			userId: $userId,
			strategy: $strategyEnum,
		);
		try
		{
			$result = $command->run();
		}
		catch (CommandValidationException $e)
		{
			$this->addErrors($e->getValidationErrors());

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getTemplate();
	}

	public function updateAction(
		CurrentUser $currentUser,
		int $templateId,
		string $title,
		string $body,
	): ?CustomTemplateDetails
	{
		$userId = (int)$currentUser->getId();
		$binding = $this->getTemplateBinding($templateId);
		if ($binding === null)
		{
			return null;
		}

		$zoneProvider = $this->getZoneProvider($binding);
		if ($zoneProvider === null)
		{
			return null;
		}
		if (!$zoneProvider->canUpdateTemplate($userId, $binding))
		{
			$this->addAccessDeniedError();

			return null;
		}

		$command = new UpdateCustomTemplateCommand(
			templateId: $templateId,
			template: new TemplateValue($title, $body),
			userId: $userId,
		);
		try
		{
			$result = $command->run();
		}
		catch (CommandValidationException $e)
		{
			$this->addErrors($e->getValidationErrors());

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getTemplate();
	}

	public function deleteAction(CurrentUser $currentUser, int $templateId): bool
	{
		$userId = (int)$currentUser->getId();
		$binding = $this->getTemplateBinding($templateId);
		if ($binding === null)
		{
			return false;
		}

		$zoneProvider = $this->getZoneProvider($binding);
		if ($zoneProvider === null)
		{
			return false;
		}
		if (!$zoneProvider->canDeleteTemplate($userId, $binding))
		{
			$this->addAccessDeniedError();

			return false;
		}

		$command = new DeleteCustomTemplateCommand(
			templateId: $templateId,
			userId: $userId,
		);
		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	private function getRegistry(): CustomTemplateZoneRegistry
	{
		return ServiceLocator::getInstance()->get(CustomTemplateZoneRegistry::class);
	}

	private function getProvider(): CustomTemplateProvider
	{
		return ServiceLocator::getInstance()->get(CustomTemplateProvider::class);
	}

	private function getTemplateBinding(int $templateId): ?TemplateBinding
	{
		$binding = $this->getProvider()->getBindingById($templateId);
		if ($binding === null)
		{
			$this->addNotFoundError();

			return null;
		}

		return $binding;
	}

	private function getZoneProvider(TemplateBinding $binding): ?AbstractZoneProvider
	{
		$zoneProvider = $this->getRegistry()->get($binding->zone);
		if ($zoneProvider === null)
		{
			$this->addAccessDeniedError();

			return null;
		}

		return $zoneProvider;
	}

	private function addNotFoundError(): void
	{
		$this->addError(new Error(Loc::getMessage('MSGSVC_CT_ERROR_NOT_FOUND'), 'CUSTOM_TEMPLATE_NOT_FOUND'));
	}

	private function addAccessDeniedError(): void
	{
		$this->addError(new Error(Loc::getMessage('MSGSVC_CT_ERROR_ACCESS_DENIED'), 'ACCESS_DENIED'));
	}
}
