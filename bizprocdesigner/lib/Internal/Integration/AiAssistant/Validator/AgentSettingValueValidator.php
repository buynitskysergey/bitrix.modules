<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\Bizproc\Integration\AiAssistant\ActivityAiPropertyConverter;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

class AgentSettingValueValidator
{
	private string|array|null $expectedTypeValue = null;

	public function validate(mixed $value, string $path, ?Setting $settingConfig): Result
	{
		$this->expectedTypeValue = null;
		$valuePath = "{$path}.value";
		if (!is_string($value) && !is_array($value))
		{
			return (new Result())->addError(GraphError::at($valuePath, "{$valuePath} is required"));
		}

		if ($settingConfig === null)
		{
			// setting is not described by the schema (e.g. it came from an existing template),
			// keep the value as is so the draft round-trip does not lose it
			$this->expectedTypeValue = $value;

			return new Result();
		}

		if ($settingConfig->type === ActivityAiPropertyConverter::SETTING_TYPE_MAP)
		{
			if (is_string($value))
			{
				$value = $this->getValueAsArray($value);
			}

			if (!is_array($value))
			{
				return (new Result())->addError(GraphError::at($valuePath, "{$valuePath} expected type is object"));
			}
			$this->expectedTypeValue = $value;

			return new Result();
		}

		if (!$settingConfig->multiple && !is_string($value))
		{
			return (new Result())->addError(GraphError::at($valuePath, "{$valuePath} expected type is string"));
		}

		if ($settingConfig->required && ($value === '' || (is_array($value) && count($value) === 0)))
		{
			return (new Result())->addError(GraphError::at($valuePath, "{$valuePath} should not be empty"));
		}

		if ($settingConfig->options !== null && is_string($value) && $value !== '')
		{
			$validIds = [];
			foreach ($settingConfig->options as $option)
			{
				$validIds[] = $option->id;
			}
			if (!in_array($value, $validIds, true))
			{
				return (new Result())->addError(GraphError::at(
					$valuePath,
					"{$valuePath} is not valid. Allowed values: " . implode(', ', $validIds),
				));
			}
		}

		$this->expectedTypeValue = $value;

		return new Result();
	}

	private function getValueAsArray(string|array $value): mixed
	{
		if (is_array($value))
		{
			return $value;
		}

		try
		{
			return Json::decode($value);
		}
		catch (ArgumentException)
		{
			return null;
		}
	}

	public function getExpectedTypeValue(): array|string|null
	{
		return $this->expectedTypeValue;
	}
}
