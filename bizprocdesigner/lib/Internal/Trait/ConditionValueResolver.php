<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Trait;

use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpression\FieldDto;
use CBPRuntime;

trait ConditionValueResolver
{
	/**
	 * @param array $documentType Document type of the edited template.
	 * @param array|null $conditionDocumentType Document type the `Document` object of the condition addresses
	 *   while that is not the one of the template ({@see self::resolveConditionDocumentType()}). Only the
	 *   fields of that object take it: every other source of a value - a constant, a variable, the output of
	 *   another block - belongs to the template and is normalised by its document type.
	 */
	protected function resolveConditionValue(
		FieldDto $field,
		mixed $value,
		array $documentType,
		?array $conditionDocumentType = null,
	): mixed
	{
		if (!is_array($value) || array_is_list($value))
		{
			return $value;
		}

		$fieldName = null;
		foreach (array_keys($value) as $key)
		{
			if (!str_ends_with($key, '_text'))
			{
				$fieldName = $key;
				break;
			}
		}

		if ($fieldName === null)
		{
			return $value;
		}

		$fieldProperty = [
			'Type' => $field->type,
			'Multiple' => $field->multiple,
			'Options' => $field->options,
			'Settings' => $field->settings,
			'Id' => $fieldName,
		];

		$errors = [];

		// 'Document' is the object of the document itself, the way \CBPMixedCondition reads a condition entry.
		$valueDocumentType = $conditionDocumentType !== null && $field->object === 'Document'
			? $conditionDocumentType
			: $documentType
		;

		return CBPRuntime::getRuntime()
			->getDocumentService()
			->getFieldInputValue($valueDocumentType, $fieldProperty, $fieldName, $value, $errors)
			?? ''
		;
	}

	/**
	 * Document type the `Document` object of the condition of a node addresses, or null while that is the
	 * document type of the edited template.
	 *
	 * A trigger is the one node whose condition is decided before the process starts, on a stub workflow bound
	 * to the document of its event, so `Document` there names the document type the node publishes
	 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::getPublishedDocumentTypeForNode()})
	 * - the very type the settings panel builds the field of the condition by. The condition of every other
	 * node is decided on the workflow itself ({@see \CBPWorkflow::isNodeConditionMet()}), where `Document` is
	 * the document of the template: that is why a published type alone is not the answer here, a complex node
	 * declares one on its class as well.
	 */
	public function resolveConditionDocumentType(string $activityType, array $activityProperties): ?array
	{
		if ($activityType === '')
		{
			return null;
		}

		$container = BizprocContainer::instance();
		if (!$container->getActivitySearcherService()->isTriggerActivity($activityType))
		{
			return null;
		}

		return $container
			->getComplexActivityService()
			->getPublishedDocumentTypeForNode($activityType, $activityProperties)
		;
	}
}
