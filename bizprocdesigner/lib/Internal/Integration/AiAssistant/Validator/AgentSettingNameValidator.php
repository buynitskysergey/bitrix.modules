<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\Main\Result;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;

final class AgentSettingNameValidator
{
	private ?Setting $setting = null;

	public function validate(mixed $name, string $path, ?BlockTypeDetail $blockTypeDetail): Result
	{
		$this->setting = null;
		$namePath = "{$path}.name";
		if (!is_string($name) || $name === '')
		{
			return (new Result())->addError(GraphError::at($namePath, "{$namePath} should be not empty string"));
		}

		if ($blockTypeDetail)
		{
			$this->setting = $blockTypeDetail->settings->findFirstByName($name);
			if ($this->setting === null && !in_array($name, $blockTypeDetail->skippedSettings, true))
			{
				return (new Result())->addError(GraphError::at($namePath, "{$namePath} is incorrect"));
			}
		}

		return new Result();
	}

	public function getSetting(): ?Setting
	{
		return $this->setting;
	}
}