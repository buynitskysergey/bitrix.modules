<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\BaasManager;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\CallChannelSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\ChannelSettingsFactory;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\ChannelSettingsInterface;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\ChatChannelSettings;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use CCrmOwnerType;
use JsonSerializable;

final class FillFieldsSettings implements AutoStartInterface, JsonSerializable
{
	private array $channelSettings;
	private array $scenarioOverrides;

	public function __construct(array $channelSettings = [], array $scenarioOverrides = [])
	{
		$this->channelSettings = $channelSettings;
		$this->scenarioOverrides = $scenarioOverrides;
	}

	public function getScenarioOverrides(): array
	{
		return $this->scenarioOverrides;
	}

	public function hasScenarioOverrides(): bool
	{
		return $this->scenarioOverrides !== [];
	}

	public function withScenarioOverrides(array $scenarioOverrides): self
	{
		$clone = clone $this;
		$clone->scenarioOverrides = $scenarioOverrides;

		return $clone;
	}

	public function getScenarioOverride(string $scenarioCode, string $channel): ?array
	{
		return $this->scenarioOverrides[$scenarioCode][$channel] ?? null;
	}

	public function addChannelSettings(ChannelSettingsInterface $settings): void
	{
		$this->channelSettings[$settings->getChannelType()] = $settings;
	}

	public function getChannelSettings(string $channelType): ?ChannelSettingsInterface
	{
		return $this->channelSettings[$channelType] ?? null;
	}

	public function shouldAutostart(
		int $operationType,
		int $callDirection,
		bool $checkAutomaticProcessingParams = true
	): bool
	{
		if (
			$checkAutomaticProcessingParams
			&& !(AIManager::isAiCallAutomaticProcessingAllowed() && BaasManager::hasPackage())
		)
		{
			return false;
		}

		// backwards compatibility for calls
		$callSettings = $this->getChannelSettings(CallChannelSettings::CHANNEL_TYPE);
		if ($callSettings === null)
		{
			return false;
		}

		return $callSettings->shouldAutostart($operationType, [
			'callDirection' => $callDirection,
			'checkAutomaticProcessingParams' => $checkAutomaticProcessingParams,
		]);
	}

	public function isAutostartTranscriptionOnlyOnFirstCallWithRecording(): bool
	{
		// backwards compatibility for calls
		$callSettings = $this->getChannelSettings(CallChannelSettings::CHANNEL_TYPE);
		if ($callSettings instanceof CallChannelSettings)
		{
			return $callSettings->isAutostartTranscriptionOnlyOnFirstCallWithRecording();
		}

		return false;
	}

	public function jsonSerialize(): array
	{
		$result = array_map(static function ($settings) {
			return $settings->toArray();
		}, $this->channelSettings);

		$payload = [
			'channels' => $result,
		];

		if ($this->scenarioOverrides !== [])
		{
			$payload['scenarioOverrides'] = $this->scenarioOverrides;
		}

		return $payload;
	}

	public static function fromJson(array $json): ?self
	{
		$scenarioOverrides = is_array($json['scenarioOverrides'] ?? null) ? $json['scenarioOverrides'] : [];

		// backwards compatibility for calls
		if (!isset($json['channels']))
		{
			$callSettings = CallChannelSettings::fromArray($json);
			if ($callSettings !== null)
			{
				return new self(
					[
						CallChannelSettings::CHANNEL_TYPE => $callSettings,
						ChatChannelSettings::CHANNEL_TYPE => ChatChannelSettings::getDefault(),
					],
					$scenarioOverrides,
				);
			}

			return null;
		}

		// new format
		$channelSettings = [];
		foreach ($json['channels'] as $channelType => $data)
		{
			$settings = ChannelSettingsFactory::create($channelType, $data);
			if ($settings !== null)
			{
				$channelSettings[$channelType] = $settings;
			}
		}

		return new self($channelSettings, $scenarioOverrides);
	}

	public static function getDefault(): self
	{
		return new self([
			CallChannelSettings::CHANNEL_TYPE => CallChannelSettings::getDefault(),
			ChatChannelSettings::CHANNEL_TYPE => ChatChannelSettings::getDefault(),
		]);
	}

	public static function get(int $entityTypeId, ?int $categoryId = null): self
	{
		$settingsRaw = Option::get('crm', self::getOptionName($entityTypeId, $categoryId));
		if ($settingsRaw === '')
		{
			return self::getDefault();
		}

		try
		{
			$settingsJson = Json::decode($settingsRaw);
		}
		catch (ArgumentException)
		{
			$settingsJson = [];
		}

		$settings = self::fromJson($settingsJson);

		return $settings instanceof self ? $settings : self::getDefault();
	}

	public static function hasSavedValue(int $entityTypeId, ?int $categoryId = null): bool
	{
		return Option::get('crm', self::getOptionName($entityTypeId, $categoryId)) !== '';
	}

	public static function getRawValue(int $entityTypeId, ?int $categoryId = null): string
	{
		return Option::get('crm', self::getOptionName($entityTypeId, $categoryId));
	}

	public static function getFreshRawValue(int $entityTypeId, ?int $categoryId = null): string
	{
		$connectionPool = Application::getInstance()->getConnectionPool();
		$connectionPool->useMasterOnly(true);

		try
		{
			$connection = Application::getConnection();
			$sqlHelper = $connection->getSqlHelper();
			$optionName = $sqlHelper->forSql(self::getOptionName($entityTypeId, $categoryId));
			$value = $connection->queryScalar(
				"SELECT VALUE FROM b_option WHERE MODULE_ID = 'crm' AND NAME = '{$optionName}'"
			);

			return is_string($value) ? $value : '';
		}
		finally
		{
			$connectionPool->useMasterOnly(false);
		}
	}

	public static function save(self $settings, int $entityTypeId, ?int $categoryId = null): Result
	{
		$result = new Result();

		if (!CCrmOwnerType::IsDefined($entityTypeId))
		{
			return $result->addError(new Error('Unknown entityTypeId', ErrorCode::INVALID_ARG_VALUE));
		}

		Option::set(
			'crm',
			self::getOptionName($entityTypeId, $categoryId),
			Json::encode($settings->jsonSerialize()),
		);

		return $result;
	}

	public static function checkSavePermissions(int $entityTypeId, ?int $categoryId = null, ?int $userId = null): bool
	{
		return self::checkReadPermissions($entityTypeId, $categoryId, $userId);
	}

	public static function checkReadPermissions(int $entityTypeId, ?int $categoryId = null, ?int $userId = null): bool
	{
		$userPermissions = Container::getInstance()->getUserPermissions($userId)->entityType();
		return (is_null($categoryId))
			? $userPermissions->canUpdateItems($entityTypeId)
			: $userPermissions->canUpdateItemsInCategory($entityTypeId, $categoryId)
		;
	}

	private static function getOptionName(int $entityTypeId, ?int $categoryId): string
	{
		if ($categoryId === null)
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);
			if ($factory?->isCategoriesSupported())
			{
				$categoryId = $factory?->getDefaultCategory()?->getId();
			}
		}

		$typeKey = (string)($entityTypeId);
		if ($categoryId !== null)
		{
			$typeKey .= "_$categoryId";
		}

		return "ai_autostart_settings_$typeKey";
	}
}
