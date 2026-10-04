<?php

namespace Bitrix\Crm\Recurring\Entity;

use Bitrix\Crm\AutomatedSolution\CapabilityAccessChecker;
use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Model\Dynamic\RecurringTable;
use Bitrix\Crm\Recurring\Mail\DocumentManager;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\DocumentGenerator\Template;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

final class Dynamic extends Base
{
	use Singleton;

	public const UNSET_DATE_PAY_BEFORE = 0;
	public const SET_DATE_PAY_BEFORE = 1;
	private array $templates = [];

	public function getList(array $parameters = [])
	{
		return RecurringTable::getList($parameters);
	}

	public function createEntity(array $entityFields, array $recurringParams): Result
	{
		$result = new Result();
		try
		{
			$entity = Item\DynamicNew::create();
			$entity->initFields($recurringParams);
			$entity->setTemplateFields($entityFields);
			$result = $entity->save();
		}
		catch (\Exception $exception)
		{
			$result->addError(new Error($exception->getMessage(), $exception->getCode()));
		}

		return $result;
	}

	public function add(array $fields): Result
	{
		$invoiceItem = Item\DynamicNew::create();
		$invoiceItem->initFields($fields);

		return $invoiceItem->save();
	}

	public function update($primary, array $data): Result
	{
		$entity = Item\DynamicExist::load($primary);
		if (!$entity)
		{
			$result = new Result();
			$result->addError(new Error('Recurring invoice not found'));
		}

		$entity->setFields($data);

		return $entity->save();
	}

	public function expose(array $filter, $limit = null, bool $recalculate = true): Result
	{
		$result = new Result();

		$recurringMap = [];
		$newItemsIds = [];

		$params = [
			'filter' => $filter,
			'select' => ['ID', 'ITEM_ID', 'ENTITY_TYPE_ID'],
		];

		$limit = (int)$limit;
		if ($limit > 0)
		{
			$params['limit'] = $limit;
		}

		$recurring = $this->getList($params);
		while ($recurData = $recurring->fetch())
		{
			$itemIdentifier = new ItemIdentifier($recurData['ENTITY_TYPE_ID'], $recurData['ITEM_ID']);
			$itemIdentifiers[$itemIdentifier->getEntityTypeId()][] = $itemIdentifier;
			$recurringMap[$itemIdentifier->getHash()] = $recurData['ID'];
		}

		if (empty($itemIdentifiers))
		{
			return $result;
		}

		try
		{
			$emailList = [];
			$emailData = [];

			foreach ($itemIdentifiers as $entityTypeId => $entityTypeItemIdentifiers)
			{
				if (CapabilityAccessChecker::getInstance()->isLockedEntityType($entityTypeId))
				{
					continue;
				}

				$itemIdentifiersChunks = array_chunk($entityTypeItemIdentifiers, 100);

				foreach ($itemIdentifiersChunks as $itemIdentifiersChunk)
				{
					/**@var ItemIdentifier[] $itemIdentifiersChunk * */
					$factory = Container::getInstance()->getFactory($entityTypeId);
					if (!$factory || !$factory->isRecurringEnabled())
					{
						continue;
					}

					$ids = $this->getEntityIdsFromItemIdentifiers($itemIdentifiersChunk);
					if (empty($ids))
					{
						continue;
					}

					$itemsData = $factory->getItems(['filter' => ['ID' => $ids, 'IS_RECURRING' => 'Y']]);
					foreach ($itemsData as $item)
					{
						$recurringItemIdentifier = new ItemIdentifier($item->getEntityTypeId(), $item->getId());

						$recurringItem = Item\DynamicExist::load($recurringMap[$recurringItemIdentifier->getHash()]);
						if (!$recurringItem)
						{
							continue;
						}

						$recurringItem->setTemplateItem($item);
						$r = $recurringItem->expose($recalculate);

						if ($r->isSuccess())
						{
							$exposingData = $r->getData();

							$newItemId = $exposingData['NEW_ITEM_ID'];
							$newItemsIds[] = $newItemId;

							if ($recurringItem->canSendEmail())
							{
								$preparedEmailData = $recurringItem->getPreparedEmailData();
								$emailList = [
									...$emailList,
									...$preparedEmailData['EMAIL_IDS'],
								];

								$entityTypeId = $recurringItemIdentifier->getEntityTypeId();
								$emailData[$entityTypeId][$newItemId] = [
									'EMAIL_IDS' => $preparedEmailData['EMAIL_IDS'],
									'EMAIL_TEMPLATE_ID' => $preparedEmailData['EMAIL_TEMPLATE_ID'] ?? null,
									'EMAIL_DOCUMENT_ID' => $preparedEmailData['EMAIL_DOCUMENT_ID'] ?? null,
									'RECURRING_ITEM_ID' => $recurringItemIdentifier->getEntityId(),
								];
							}
						}
						else
						{
							$result->addErrors($r->getErrors());
							if ($recalculate)
							{
								$recurringItem->deactivate();
								$recurringItem->save();
							}
						}
						unset($recurringItem);
					}
				}

				if (!empty($emailList))
				{
					$emailList = array_unique($emailList);
					$result = $this->sendByMail($emailList, $emailData);
				}
			}
		}
		catch (SystemException $exception)
		{
			$result->addError(new Error($exception->getMessage(), $exception->getCode()));
		}

		if (!empty($newItemsIds))
		{
			$result->setData(['ID' => $newItemsIds]);
		}

		return $result;
	}

	public function exposeAutomatically(
		int $limit,
		?DateTime $serverMoment = null,
		?Date $cursorDate = null,
		int $cursorId = 0,
	): Result
	{
		$selection = (new DynamicAgentSelector())->select($limit, $serverMoment, $cursorDate, $cursorId);
		$result = $this->exposeSelectedItems($selection[DynamicAgentSelector::KEY_ITEMS]);
		$data = $result->getData();
		$data[DynamicAgentSelector::KEY_HAS_CANDIDATES] = $selection[DynamicAgentSelector::KEY_HAS_CANDIDATES];
		$data[DynamicAgentSelector::KEY_NEXT_CURSOR_DATE] = $selection[DynamicAgentSelector::KEY_NEXT_CURSOR_DATE];
		$data[DynamicAgentSelector::KEY_NEXT_CURSOR_ID] = $selection[DynamicAgentSelector::KEY_NEXT_CURSOR_ID];
		$result->setData($data);

		return $result;
	}

	private function exposeSelectedItems(array $selectedItems): Result
	{
		$result = new Result();
		$newItemsIds = [];
		$emailList = [];
		$emailData = [];

		try
		{
			$currentRecurringFields = $this->loadCurrentRecurringFields($selectedItems);
			foreach ($selectedItems as $selectedItem)
			{
				$recurringFields = $selectedItem[DynamicAgentSelector::KEY_RECURRING_FIELDS];
				$templateItem = $selectedItem[DynamicAgentSelector::KEY_TEMPLATE_ITEM];
				$executionContext = $selectedItem[DynamicAgentSelector::KEY_EXECUTION_CONTEXT];
				$recurringId = (int)$recurringFields['ID'];

				$actualFields = $currentRecurringFields[$recurringId] ?? null;
				$recurringItem = is_array($actualFields)
					? Item\DynamicExist::createFromFields($actualFields, $executionContext)
					: null;
				if (!$recurringItem || !$recurringItem->hasSameAutomaticExposureState($recurringFields))
				{
					continue;
				}

				$recurringItem->setTemplateItem($templateItem);
				$exposeResult = $recurringItem->expose(true);
				if (!$exposeResult->isSuccess())
				{
					$result->addErrors($exposeResult->getErrors());
					$recurringItem->deactivate();
					$recurringItem->save();

					continue;
				}

				$exposingData = $exposeResult->getData();
				$newItemId = (int)$exposingData['NEW_ITEM_ID'];
				$newItemsIds[] = $newItemId;

				if (!$recurringItem->canSendEmail())
				{
					continue;
				}

				$preparedEmailData = $recurringItem->getPreparedEmailData();
				$emailList = [
					...$emailList,
					...$preparedEmailData['EMAIL_IDS'],
				];
				$entityTypeId = (int)$recurringFields['ENTITY_TYPE_ID'];
				$emailData[$entityTypeId][$newItemId] = [
					'EMAIL_IDS' => $preparedEmailData['EMAIL_IDS'],
					'EMAIL_TEMPLATE_ID' => $preparedEmailData['EMAIL_TEMPLATE_ID'] ?? null,
					'EMAIL_DOCUMENT_ID' => $preparedEmailData['EMAIL_DOCUMENT_ID'] ?? null,
					'RECURRING_ITEM_ID' => (int)$recurringFields['ITEM_ID'],
				];
			}

			if (!empty($emailList))
			{
				$sendResult = $this->sendByMail(array_unique($emailList), $emailData);
				$result->addErrors($sendResult->getErrors());
			}
		}
		catch (SystemException $exception)
		{
			$result->addError(new Error($exception->getMessage(), $exception->getCode()));
		}

		if (!empty($newItemsIds))
		{
			$result->setData(['ID' => $newItemsIds]);
		}

		return $result;
	}

	private function loadCurrentRecurringFields(array $selectedItems): array
	{
		$ids = [];
		foreach ($selectedItems as $selectedItem)
		{
			$id = (int)($selectedItem[DynamicAgentSelector::KEY_RECURRING_FIELDS]['ID'] ?? 0);
			if ($id > 0)
			{
				$ids[$id] = $id;
			}
		}

		if (empty($ids))
		{
			return [];
		}

		$rows = RecurringTable::query()
			->setSelect(['*'])
			->whereIn('ID', array_values($ids))
			->fetchAll()
		;
		$fieldsById = [];
		foreach ($rows as $row)
		{
			$fieldsById[(int)$row['ID']] = $row;
		}

		return $fieldsById;
	}

	private function getEntityIdsFromItemIdentifiers(array $itemIdentifiers): array
	{
		$ids = [];
		foreach ($itemIdentifiers as $itemIdentifier)
		{
			$ids[] = $itemIdentifier->getEntityId();
		}

		return $ids;
	}

	public function cancel($entityId, $reason = ''): void
	{
		$this->deactivate($entityId);
	}

	public function deactivate($entityId): Result
	{
		return $this->update($entityId, ['ACTIVE' => 'N']);
	}

	public function activate($entityId): Result
	{
		return $this->update($entityId, ['ACTIVE' => 'Y']);
	}

	// @todo ***recurring
	public function isAllowedExpose(): bool
	{
		return RestrictionManager::getInvoiceRecurringRestriction()?->hasPermission() ?? false;
	}

	public static function getParameterMapper(array $params = [])
	{
		return Item\DynamicEntity::getFormMapper($params);
	}

	public static function getNextDate(array $params, $startDate = null, $currentDate = null): ?Date
	{
		$mapper = self::getParameterMapper($params);
		$mapper->fillMap($params);

		return parent::getNextDateWithCurrentDate($mapper->getPreparedMap(), $startDate, $currentDate);
	}

	public function delete($primary): Result
	{
		$entity = Item\DynamicExist::load($primary);
		if (!$entity)
		{
			$result = new Result();
			$result->addError(new Error('Recurring smart invoice not found'));

			return $result;
		}

		return $entity->delete();
	}

	public function getRuntimeTemplateField(): array
	{
		return [];
	}

	public function sendByMail(array $emailList, array $emailData): Result
	{
		$documentManager = DocumentManager::getInstance();

		foreach ($emailData as $entityTypeId => $emailDataSection)
		{
			$documentManager->setEntityTypeId($entityTypeId);

			$idListChunks = array_chunk(array_keys($emailDataSection), 999);

			$factory = Container::getInstance()->getFactory($entityTypeId);
			if (!$factory || !$factory->isRecurringEnabled())
			{
				continue;
			}

			foreach ($idListChunks as $idList)
			{
				$newItemData = $factory->getItems([
					'filter' => ['@ID' => $idList],
					'select' => ['*', 'UF_*'],
				]);

				foreach ($newItemData as $itemData)
				{
					$documentGenerator = DocumentGeneratorManager::getInstance();
					$provider = $documentGenerator->getCrmOwnerTypeProvider($entityTypeId, false);

					$itemId = $itemData->getId();
					$emailTemplateId = $emailDataSection[$itemId]['EMAIL_TEMPLATE_ID'] ?? null;
					$emailDocumentId = $emailDataSection[$itemId]['EMAIL_DOCUMENT_ID'] ?? null;

					if ($emailTemplateId <= 0 || $emailDocumentId <= 0)
					{
						continue;
					}

					$template = $this->getTemplateById($emailDocumentId);
					if (!$template)
					{
						continue;
					}

					$template = clone $template;
					$template->setSourceType($provider);

					$document = \Bitrix\DocumentGenerator\Document::createByTemplate($template, $itemId);

					if (!$document)
					{
						continue;
					}

					$document->getFile();

					if ($document->ID)
					{
						$documentManager
							->setEntityId($itemId)
							->setDocumentId($document->ID)
							->setRecurringItemId($emailDataSection[$itemId]['RECURRING_ITEM_ID'])
							->setEmailTemplateId($emailTemplateId)
							->bind()
						;
					}
				}
			}
		}

		return new Result();
	}

	private function getTemplateById(int $templateId): ?Template
	{
		if (isset($this->templates[$templateId]))
		{
			return $this->templates[$templateId];
		}

		$this->templates[$templateId] = Template::loadById($templateId);

		return $this->templates[$templateId];
	}
}
