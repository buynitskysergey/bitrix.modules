<?php

namespace Bitrix\Crm\Automation\Trigger\Sign\B2e;

use Bitrix\Crm\Automation\Trigger\Sign\SignTriggerReturnDataTrait;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Integration\CRM\Model\EventData;
use Bitrix\Crm\Automation;

class AbstractB2eDocumentTrigger extends Automation\Trigger\BaseTrigger
{
	use SignTriggerReturnDataTrait;

	private const SUPPORTED_TYPE_LIST = [
		\CCrmOwnerType::SmartB2eDocument,
	];

	public static function isEnabled(): bool
	{
		return Loader::includeModule('sign')
			&& method_exists(Storage::instance(), 'isB2eAvailable')
			&& Storage::instance()->isB2eAvailable();
	}

	public static function isSupported($entityTypeId): bool
	{
		if (\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId) && self::isB2eRobotEnabled())
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);

			return
				static::areDynamicTypesSupported()
				&& !is_null($factory)
				&& $factory->isAutomationEnabled()
				&& $factory->isStagesEnabled();
		}

		return in_array($entityTypeId, self::SUPPORTED_TYPE_LIST, true);
	}

	private static function isB2eRobotEnabled(): bool
	{
		if (static::isEnabled() === false)
		{
			return false;
		}

		$feature = Feature::instance();

		if (!method_exists($feature, 'isB2eRobotEnabled'))
		{
			return false;
		}

		return $feature->isB2eRobotEnabled();
	}

	public static function executeBySmartDocumentId(
		int $smartDocumentId,
		array $inputData = null,
	): Result
	{
		$result = new Result();
		if ($smartDocumentId < 1)
		{
			return $result->addError(new Error('Invalid smart document id'));
		}

		$bindings[] = [
			'OWNER_ID' => $smartDocumentId,
			'OWNER_TYPE_ID' => \CCrmOwnerType::SmartB2eDocument,
		];

		$itemIdentifier= new ItemIdentifier(
			\CCrmOwnerType::SmartB2eDocument,
			$smartDocumentId,
		);
		$relatedIdentifierList = Container::getInstance()->getRelationManager()->getParentElements($itemIdentifier);

		foreach ($relatedIdentifierList as $identifier)
		{
			if (\CCrmOwnerType::isPossibleDynamicTypeId($identifier->getEntityTypeId()) === false)
			{
				continue;
			}

			$bindings[] = [
				'OWNER_ID' => $identifier->getEntityId(),
				'OWNER_TYPE_ID' => $identifier->getEntityTypeId(),
			];
		}

		return static::execute($bindings, $inputData);
	}

	public static function getGroup(): array
	{
		return ['paperwork'];
	}

	public static function toArray(): array
	{
		$result = parent::toArray();
		if (
			static::isEnabled()
			&& Loader::includeModule('bitrix24')
			&& !\Bitrix\Bitrix24\Feature::isFeatureEnabled('sign_b2e')
		)
		{
			$result['LOCKED'] = [
				'INFO_CODE' => 'limit_office_e_signature',
			];
		}

		return $result;
	}

	public function setInputData($data)
	{
		if (is_callable([$this, 'setReturnValues']))
		{
			$this->setReturnValues(static::buildSignReturnValues(is_array($data) ? $data : []));
		}

		return parent::setInputData($data);
	}

	public static function getReturnProperties(): array
	{
		return array_merge(parent::getReturnProperties(), static::getSignReturnProperties());
	}

	/**
	 * Each concrete node lists its own fields. This class is not a node itself and only satisfies the
	 * trait, so an empty set here makes a node that forgot to declare its fields visible.
	 */
	protected static function getSignReturnFieldIds(): array
	{
		return [];
	}

	/**
	 * Whether the installed sign module sends the acting user with the event. Modules are updated
	 * independently, so a node whose event does name an actor declares the initiator only under this
	 * check: on a portal with the older sign the field would always be empty.
	 *
	 * TODO temporary: drop this method together with the declarations guarded by it once the sign
	 * update that sends the acting user is released.
	 */
	protected static function isInitiatorSentBySign(): bool
	{
		return Loader::includeModule('sign') && method_exists(EventData::class, 'setInitiatorUserId');
	}
}
