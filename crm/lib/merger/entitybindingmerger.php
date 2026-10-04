<?php
namespace Bitrix\Crm\Merger;
use Bitrix\Crm;

abstract class EntityBindingMerger
{
	private $entityTypeID = \CCrmOwnerType::Undefined;
	private $bindingFieldName = '';
	private $identityFieldName = '';
	public function __construct($entityTypeID, $bindingFieldName, $identityFieldName = '')
	{
		$this->entityTypeID = $entityTypeID;

		$this->bindingFieldName = $bindingFieldName;
		$this->identityFieldName = $identityFieldName;
	}

	abstract protected function getBindings(array $entityFields);
	abstract protected function getMappedIDs(array $map);

	public function merge(array &$seeds, array &$targ, $skipEmpty = false, array $options = array())
	{
		$resultSeedBindings = array();

		$effectiveIDs = $this->prepareEffectiveIDs($seeds, $targ, $options);

		foreach($seeds as $seed)
		{
			$seedID = isset($seed['ID']) ? (int)$seed['ID'] : 0;
			if($effectiveIDs !== null && !in_array($seedID, $effectiveIDs))
			{
				continue;
			}

			$seedBindings = $this->getBindings($seed);
			if($seedBindings !== null)
			{
				EntityMerger::mergeEntityBindings(
					$this->entityTypeID,
					$seedBindings,
					$resultSeedBindings
				);
			}
		}

		$targID = isset($targ['ID']) ? (int)$targ['ID'] : 0;
		if($effectiveIDs !== null)
		{
			$targBindings = null;
			if(in_array($targID, $effectiveIDs))
			{
				$targBindings = $this->getBindings($targ);
			}

			if($targBindings === null || count($targBindings) === 0)
			{
				$targBindings = $resultSeedBindings;
			}
			else
			{
				EntityMerger::mergeEntityBindings(
					$this->entityTypeID,
					$resultSeedBindings,
					$targBindings
				);
			}

			$targ[$this->bindingFieldName] = $targBindings;
		}
		else if(!empty($resultSeedBindings))
		{
			$targBindings = $this->getBindings($targ);
			$skipMultipleFields = isset($options['SKIP_MULTIPLE_USER_FIELDS']) && $options['SKIP_MULTIPLE_USER_FIELDS'];
			if(!$skipMultipleFields)
			{
				if($targBindings === null || count($targBindings) === 0)
				{
					$targBindings = $resultSeedBindings;
				}
				else
				{
					EntityMerger::mergeEntityBindings(
						$this->entityTypeID,
						$resultSeedBindings,
						$targBindings
					);
				}

				$targ[$this->bindingFieldName] = $targBindings;
			}
			elseif($targBindings === null || (count($targBindings) === 0 && !$skipEmpty))
			{
				$targ[$this->bindingFieldName] = $resultSeedBindings;
			}
		}

		if($this->identityFieldName !== '' && isset($targ[$this->bindingFieldName]))
		{
			$targ[$this->identityFieldName] = Crm\Binding\EntityBinding::prepareEntityIDs(
				$this->entityTypeID,
				$targ[$this->bindingFieldName]
			);
		}
	}

	public function prepareMergeData(array $seeds, array $targ, $skipEmpty = false, array $options = array())
	{
		$mergedTarg = $targ;
		$this->merge($seeds, $mergedTarg, $skipEmpty, $options);

		$bindings = $this->getBindings($mergedTarg);
		if($bindings === null)
		{
			$bindings = array();
		}

		$bindingIDs = Crm\Binding\EntityBinding::prepareEntityIDs($this->entityTypeID, $bindings);

		return array(
			'SOURCE_ENTITY_IDS' => $this->prepareSourceEntityIDs($seeds, $targ, $bindingIDs, $options),
			'VALUE' => $bindingIDs,
		);
	}

	private function prepareEffectiveIDs(array $seeds, array $targ, array $options)
	{
		$map = null;
		if(isset($options['map']) && is_array($options['map']))
		{
			$map = $options['map'];
		}

		$sourceIDs = is_array($map) ? $this->getMappedIDs($map) : null;
		if(!is_array($sourceIDs))
		{
			return null;
		}

		$effectiveIDs = array();
		if(isset($targ['ID']))
		{
			$effectiveIDs[] = (int)$targ['ID'];
		}

		foreach($seeds as $seed)
		{
			if(isset($seed['ID']))
			{
				$effectiveIDs[] = (int)$seed['ID'];
			}
		}

		return array_intersect($sourceIDs, $effectiveIDs);
	}

	private function prepareSourceEntityIDs(array $seeds, array $targ, array $bindingIDs, array $options)
	{
		if(empty($bindingIDs))
		{
			return array();
		}

		$sourceEntityIDs = array();
		$effectiveIDs = $this->prepareEffectiveIDs($seeds, $targ, $options);

		$this->collectSourceEntityID($targ, $effectiveIDs, $sourceEntityIDs);
		foreach($seeds as $seed)
		{
			$this->collectSourceEntityID($seed, $effectiveIDs, $sourceEntityIDs);
		}

		return array_values(array_unique($sourceEntityIDs, SORT_NUMERIC));
	}

	private function collectSourceEntityID(
		array $entityFields,
		$effectiveIDs,
		array &$sourceEntityIDs
	)
	{
		$entityID = isset($entityFields['ID']) ? (int)$entityFields['ID'] : 0;
		if($entityID <= 0 || ($effectiveIDs !== null && !in_array($entityID, $effectiveIDs)))
		{
			return;
		}

		$bindings = $this->getBindings($entityFields);
		if($bindings === null || empty($bindings))
		{
			return;
		}

		$sourceEntityIDs[] = $entityID;
	}
}
