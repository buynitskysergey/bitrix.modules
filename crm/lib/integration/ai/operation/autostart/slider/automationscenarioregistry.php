<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\Slider;

use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;

final class AutomationScenarioRegistry
{
	public const CHANNEL_CALL = 'call';
	public const CHANNEL_CHAT = 'chat';
	public const CHANNEL_EMAIL = 'email';

	public const SCENARIO_TRANSCRIPTION = 'transcription';
	public const SCENARIO_SUMMARIZE = 'summarize';
	public const SCENARIO_FILL_FIELDS = 'fillFields';
	public const SCENARIO_ANALYZE_COMMUNICATION = 'analyzeCommunication';
	public const SCENARIO_CALL_ASSESSMENT = 'callAssessment';

	public const CALL_MODES = [
		'firstIncoming',
		'allIncoming',
		'outgoing',
		'both',
	];
	public const CHAT_MODES = [
		'firstChat',
		'all',
	];
	public const EMAIL_MODES = [
		'firstIncoming',
		'allIncoming',
		'allOutgoing',
		'all',
	];

	public function getAll(): array
	{
		return [
			self::SCENARIO_TRANSCRIPTION => [
				'operationType' => TranscribeCallRecording::TYPE_ID,
				'channels' => [
					self::CHANNEL_CALL,
				],
				'titleId' => 'CRM_AI_AUTOMATION_SLIDER_SCENARIO_TRANSCRIPTION_TITLE',
				'descriptionId' => 'CRM_AI_AUTOMATION_SLIDER_SCENARIO_TRANSCRIPTION_DESCRIPTION',
			],
			self::SCENARIO_SUMMARIZE => [
				'operationType' => SummarizeCallTranscription::TYPE_ID,
				'channels' => [
					self::CHANNEL_CALL,
					self::CHANNEL_CHAT,
					self::CHANNEL_EMAIL,
				],
				'titleId' => 'CRM_AI_AUTOMATION_SLIDER_SCENARIO_SUMMARIZE_TITLE',
				'descriptionId' => 'CRM_AI_AUTOMATION_SLIDER_SCENARIO_SUMMARIZE_DESCRIPTION',
			],
			self::SCENARIO_FILL_FIELDS => [
				'operationType' => FillItemFieldsFromCallTranscription::TYPE_ID,
				'channels' => [
					self::CHANNEL_CALL,
					self::CHANNEL_CHAT,
					self::CHANNEL_EMAIL,
				],
				'titleId' => 'CRM_AI_AUTOMATION_SLIDER_SCENARIO_FILL_FIELDS_TITLE',
				'descriptionId' => 'CRM_AI_AUTOMATION_SLIDER_SCENARIO_FILL_FIELDS_DESCRIPTION',
			],
			self::SCENARIO_ANALYZE_COMMUNICATION => [
				'operationType' => AnalyzeCommunication::TYPE_ID,
				'channels' => [
					self::CHANNEL_CALL,
					self::CHANNEL_CHAT,
					self::CHANNEL_EMAIL,
				],
				'titleId' => 'CRM_AI_AUTOMATION_SLIDER_SCENARIO_ANALYZE_COMMUNICATION_TITLE',
				'descriptionId' => 'CRM_AI_AUTOMATION_SLIDER_SCENARIO_ANALYZE_COMMUNICATION_DESCRIPTION',
			],
			self::SCENARIO_CALL_ASSESSMENT => [
				'operationType' => ScoreCall::TYPE_ID,
				'channels' => [
					self::CHANNEL_CALL,
				],
				'titleId' => 'CRM_AI_AUTOMATION_SLIDER_SCENARIO_CALL_ASSESSMENT_TITLE',
				'descriptionId' => 'CRM_AI_AUTOMATION_SLIDER_SCENARIO_CALL_ASSESSMENT_DESCRIPTION',
			],
		];
	}

	public function getGlobalConfigMap(): array
	{
		return [
			self::SCENARIO_TRANSCRIPTION => [
				'tuningCode' => null,
				'engineCodes' => [
					EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE,
				],
			],
			self::SCENARIO_SUMMARIZE => [
				'tuningCode' => EventHandler::SETTINGS_SUMMARIZE_ENABLED_CODE,
				'engineCodes' => [
					EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE,
				],
			],
			self::SCENARIO_FILL_FIELDS => [
				'tuningCode' => EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENABLED_CODE,
				'engineCodes' => [
					EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE,
					EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE,
				],
			],
			self::SCENARIO_ANALYZE_COMMUNICATION => [
				'tuningCode' => EventHandler::SETTINGS_ANALYZE_COMMUNICATION_ENABLED_CODE,
				'engineCodes' => [
					EventHandler::SETTINGS_ANALYZE_COMMUNICATION_ENGINE_CODE,
				],
			],
			self::SCENARIO_CALL_ASSESSMENT => [
				'tuningCode' => EventHandler::SETTINGS_CALL_ASSESSMENT_ENABLED_CODE,
				'engineCodes' => [
					EventHandler::SETTINGS_CALL_ASSESSMENT_ENGINE_CODE,
				],
			],
		];
	}

	public function has(string $code): bool
	{
		return array_key_exists($code, $this->getAll());
	}

	public function getOperationType(string $scenarioCode): ?int
	{
		return $this->getAll()[$scenarioCode]['operationType'] ?? null;
	}

	public function getChannels(string $scenarioCode): array
	{
		return $this->getAll()[$scenarioCode]['channels'] ?? [];
	}

	public function supportsChannel(string $scenarioCode, string $channelCode): bool
	{
		return in_array($channelCode, $this->getChannels($scenarioCode), true);
	}

	public function getAvailableModes(string $channelCode): array
	{
		return match ($channelCode) {
			self::CHANNEL_CALL => self::CALL_MODES,
			self::CHANNEL_CHAT => self::CHAT_MODES,
			self::CHANNEL_EMAIL => self::EMAIL_MODES,
			default => [],
		};
	}

	public function getDefaultMode(string $channelCode): string
	{
		return match ($channelCode) {
			self::CHANNEL_CALL => 'allIncoming',
			self::CHANNEL_CHAT => 'all',
			self::CHANNEL_EMAIL => 'firstIncoming',
			default => '',
		};
	}
}
