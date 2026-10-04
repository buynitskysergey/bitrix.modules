<?php

namespace Bitrix\Crm\Merger;

use Bitrix\Crm\Binding;

class FactoryBasedContactBindingMerger extends EntityBindingMerger
{
	private bool $mergeContactsFromAllItems;

	public function __construct(bool $mergeContactsFromAllItems)
	{
		$this->mergeContactsFromAllItems = $mergeContactsFromAllItems;

		parent::__construct(
			\CCrmOwnerType::Contact,
			'CONTACT_BINDINGS',
			'CONTACT_IDS',
		);
	}

	public function merge(array &$seeds, array &$targ, $skipEmpty = false, array $options = array())
	{
		if ($this->shouldMergeContactsFromAllItems())
		{
			// Keep contacts from every merged item because seeds are deleted after merge.
			unset($options['map']['CONTACT_IDS']);
		}

		parent::merge($seeds, $targ, $skipEmpty, $options);
	}

	protected function getBindings(array $entityFields)
	{
		if (
			isset($entityFields['CONTACT_BINDINGS'])
			&& is_array($entityFields['CONTACT_BINDINGS'])
			&& !empty($entityFields['CONTACT_BINDINGS'])
		)
		{
			return $entityFields['CONTACT_BINDINGS'];
		}

		if (isset($entityFields['CONTACT_IDS']) && is_array($entityFields['CONTACT_IDS']))
		{
			$contactIds = $entityFields['CONTACT_IDS'];
		}
		elseif (isset($entityFields['CONTACT_ID']))
		{
			$contactIds = [$entityFields['CONTACT_ID']];
		}
		else
		{
			return null;
		}

		return $this->prepareBindingsFromContactIds($contactIds);
	}

	public function prepareMergeData(array $seeds, array $targ, $skipEmpty = false, array $options = array())
	{
		if (!$this->shouldMergeContactsFromAllItems())
		{
			return parent::prepareMergeData($seeds, $targ, $skipEmpty, $options);
		}

		$sourceEntityIds = [];
		$resultContactBindings = [];

		foreach ([$targ, ...$seeds] as $entityFields)
		{
			$entityId = isset($entityFields['ID']) ? (int)$entityFields['ID'] : 0;
			if ($entityId <= 0)
			{
				continue;
			}

			$contactBindings = $this->getBindings($entityFields);
			if (empty($contactBindings))
			{
				continue;
			}

			$sourceEntityIds[] = $entityId;
			EntityMerger::mergeEntityBindings(
				\CCrmOwnerType::Contact,
				$contactBindings,
				$resultContactBindings,
			);
		}

		return [
			'SOURCE_ENTITY_IDS' => array_values(array_unique($sourceEntityIds, SORT_NUMERIC)),
			'VALUE' => Binding\EntityBinding::prepareEntityIDs(\CCrmOwnerType::Contact, $resultContactBindings),
		];
	}

	protected function getMappedIDs(array $map)
	{
		if (!isset($map['CONTACT_IDS']))
		{
			return null;
		}

		return isset($map['CONTACT_IDS']['SOURCE_ENTITY_IDS'])
			&& is_array($map['CONTACT_IDS']['SOURCE_ENTITY_IDS'])
				? $map['CONTACT_IDS']['SOURCE_ENTITY_IDS']
				: []
		;
	}

	private function prepareBindingsFromContactIds(array $contactIds): array
	{
		$contactBindings = Binding\EntityBinding::prepareEntityBindings(
			\CCrmOwnerType::Contact,
			$contactIds,
		);
		Binding\EntityBinding::markFirstAsPrimary($contactBindings);

		return $contactBindings;
	}

	private function shouldMergeContactsFromAllItems(): bool
	{
		return $this->mergeContactsFromAllItems;
	}
}
