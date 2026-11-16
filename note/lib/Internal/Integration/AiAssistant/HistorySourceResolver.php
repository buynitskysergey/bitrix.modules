<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\AiAssistant;

use Bitrix\AiAssistant\Rest\SignedUserAuth;
use Bitrix\Main\Loader;
use Bitrix\Note\Internal\Entity\History\Source;
use Bitrix\Note\Internal\Entity\History\SourceType;
use Bitrix\Rest\APAuth;
use Bitrix\Rest\OAuth;
use InvalidArgumentException;

final class HistorySourceResolver
{
	public function resolve(?string $authType, array $authData): ?Source
	{
		if (in_array($authType, [OAuth\Auth::AUTH_TYPE, APAuth\Auth::AUTH_TYPE], true))
		{
			return new Source(SourceType::Application);
		}

		if (!$this->isAiAssistantAuthType($authType))
		{
			return null;
		}

		$defaultSource = new Source(SourceType::AiAssistant);
		if (!array_key_exists('subject_payload', $authData))
		{
			return $defaultSource;
		}

		$subjectPayload = $authData['subject_payload'];
		if (!is_array($subjectPayload))
		{
			throw new InvalidArgumentException('Signed subject payload must be an array.');
		}

		if (!array_key_exists('source', $subjectPayload))
		{
			return $defaultSource;
		}

		$sourceData = $subjectPayload['source'];
		if (!is_array($sourceData))
		{
			throw new InvalidArgumentException('Signed history source must be an array.');
		}

		$type = isset($sourceData['type']) && is_string($sourceData['type'])
			? SourceType::tryFrom($sourceData['type'])
			: null;
		if ($type === null)
		{
			throw new InvalidArgumentException('Unknown signed history source type.');
		}

		return new Source($type, $this->resolveSourceId($sourceData));
	}

	private function isAiAssistantAuthType(?string $authType): bool
	{
		return Loader::includeModule('aiassistant') && $authType === SignedUserAuth::AUTH_TYPE;
	}

	private function resolveSourceId(array $sourceData): ?int
	{
		if (!array_key_exists('id', $sourceData) || $sourceData['id'] === null)
		{
			return null;
		}

		$sourceId = $sourceData['id'];
		if (is_int($sourceId))
		{
			return $sourceId;
		}

		if (!is_string($sourceId) || preg_match('/^[0-9]+$/D', $sourceId) !== 1)
		{
			throw new InvalidArgumentException('Signed history source identifier must be a positive integer.');
		}

		$sourceId = filter_var($sourceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($sourceId === false)
		{
			throw new InvalidArgumentException('Signed history source identifier must be a positive integer.');
		}

		return $sourceId;
	}
}
