<?php

namespace Bitrix\Im\V2\Application;

use Bitrix\AiAssistant\Config\Restriction as AiAssistantRestriction;
use Bitrix\Im\Integration\Socialservices\Zoom;
use Bitrix\Im\Integration\Disk\Documents;
use Bitrix\Im\Settings;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Guest\GuestService;
use Bitrix\Im\V2\Integration\AI\Restriction;
use Bitrix\Im\V2\Integration\AiAssistant\AiAssistantService;
use Bitrix\Im\V2\Integration\AiAssistant\WebSearchService;
use Bitrix\Im\V2\Integration\Extranet\CollaberService;
use Bitrix\Im\V2\Integration\HumanResources\Structure;
use Bitrix\Im\V2\Integration\Intranet\Invitation;
use Bitrix\Im\V2\Integration\Sign\DocumentSign;
use Bitrix\Im\V2\Integration\Socialnetwork\Collab\Collab;
use Bitrix\ImBot\Bot\Giphy;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class Features
{
	private static self $currentFeatures;

	public function __construct(
		public readonly bool $chatV2,
		public readonly bool $chatDepartments,
		public readonly bool $copilotActive,
		public readonly bool $copilotAvailable,
		public readonly bool $copilotHistoryAccess,
		public readonly bool $sidebarLinks,
		public readonly bool $sidebarFiles,
		public readonly bool $sidebarBriefs,
		public readonly bool $zoomActive,
		public readonly bool $zoomAvailable,
		public readonly bool $openLinesV2,
		public readonly bool $giphyAvailable,
		public readonly bool $collabAvailable,
		public readonly bool $collabCreationAvailable,
		public readonly bool $enabledCollabersInvitation,
		public readonly bool $inviteByPhoneAvailable,
		public readonly bool $inviteByLinkAvailable,
		public readonly bool $documentSignAvailable,
		public readonly bool $intranetInviteAvailable,
		public readonly bool $changeInviteLanguageAvailable,
		public readonly bool $voteCreationAvailable,
		public readonly bool $messagesAutoDeleteEnabled,
		public readonly bool $teamsInStructureAvailable,
		public readonly bool $isDesktopRedirectAvailable,
		public readonly bool $aiAssistantAvailable,
		public readonly bool $isCopilotMentionAvailable,
		public readonly bool $aiFileTranscriptionAvailable,
		public readonly bool $chatSharingLinkAvailable,
		public readonly bool $isTasksRecentListAvailable,
		public readonly bool $aiAssistantMcpSelectorAvailable,
		public readonly bool $videoNoteTranscriptionAvailable,
		public readonly bool $isTextFormatToolbarAvailable,
		public readonly bool $isCounterV3Available,
		public readonly bool $isCopilotFileUploadAvailable,
		public readonly bool $isBitrixGptUploadPolicyAvailable,
		public readonly bool $isMountedTasksCardAvailable,
		public readonly bool $isBitrixGptV2Available,
		public readonly bool $isMessageBuilderAvailable,
		public readonly bool $isAddingUserByMentionAvailable,
		public readonly bool $isAddToChatPopupAvailable,
		public readonly bool $isExternalChatMessageForwardingAvailable,
		public readonly bool $isNestedChatAvailable,
		public readonly bool $isChatWithGuestsAvailable,
		public readonly bool $isCopilotForceSearchAvailable,
		public readonly bool $isCopilotWebSearchEnabledByAdmin,
		public readonly bool $isCopilotWebSearchAllowedByTariff,
		public readonly bool $isAiAssistantFeedbackAvailable,
		public readonly bool $isAiAssistantAgentModeAvailable,
		public readonly bool $isChatFoldersWebAvailable,
		public readonly bool $isAiAssistantRegenerateAvailable,
		public readonly bool $collabPreviewSourceEnabled,
		public readonly bool $isMarkdownAvailable,
		public readonly bool $isCopilotDraftChatAvailable,
		public readonly bool $isAttachChatToProjectAvailable,
		public readonly bool $isAiTaskCreationFromMessagesAvailable,
		public readonly bool $isAirDesignEnabled,
		public readonly bool $isMessageDateNavigationAvailable,
		public readonly bool $isReplyWithMediaAvailable,
		public readonly bool $isTaskResultButtonAvailable,
		public readonly bool $isMessageFormBlockAvailable,
		public readonly bool $isBitrixGptScrollPinAvailable,
		public readonly bool $isBitrixGptStopGenerationAvailable,
		public readonly bool $isChatBotCommandsAvailable,
		public readonly bool $isUploadCaptionMentionsFormatAvailable,
		public readonly bool $isVideoNotePlaybackRateAvailable,
		public readonly bool $isMentionInstantSearchAvailable,
		public readonly bool $isTaskChatAutoUnmuteAvailable,
		public readonly bool $isVibecodeAppOpenButtonAvailable,
	){}

	public static function get(): self
	{
		if (!isset(self::$currentFeatures))
		{
			self::$currentFeatures = self::createCurrent();
		}

		return self::$currentFeatures;
	}

	private static function createCurrent(): self
	{
		return new self(
			chatV2: !Settings::isLegacyChatActivated(),
			chatDepartments: Structure::isSyncAvailable(),
			copilotActive: CopilotChat::isActive(),
			copilotAvailable: CopilotChat::isAvailable(),
			copilotHistoryAccess: CopilotChat::isHistoryAvailable(),
			sidebarLinks: Option::get('im', 'im_link_url_migration', 'N') === 'Y',
			sidebarFiles: Option::get('im', 'im_link_file_migration', 'N') === 'Y',
			sidebarBriefs: Documents::getResumesOfCallStatus() === Documents::ENABLED,
			zoomActive: Zoom::isActive(),
			zoomAvailable: Zoom::isAvailable(),
			openLinesV2: self::isImOpenLinesV2Available(),
			giphyAvailable: self::isGiphyAvailable(),
			collabAvailable: Collab::isAvailable(),
			collabCreationAvailable: Collab::isCreationAvailable(),
			enabledCollabersInvitation: CollaberService::getInstance()->isEnabledCollabersInvitation(),
			inviteByPhoneAvailable: self::isInviteByPhoneAvailable(),
			inviteByLinkAvailable: self::isInviteByLinkAvailable(),
			documentSignAvailable: DocumentSign::isAvailable(),
			intranetInviteAvailable: Invitation::isAvailable(),
			changeInviteLanguageAvailable: Invitation::isChangeLanguageAvailable(),
			voteCreationAvailable: self::isVoteCreationAvailable(),
			messagesAutoDeleteEnabled: self::isMessagesAutoDeleteEnabled(),
			teamsInStructureAvailable: Structure::isTeamsAvailable(),
			isDesktopRedirectAvailable: self::isDesktopRedirectAvailable(),
			aiAssistantAvailable: self::isAiAssistantAvailable(),
			isCopilotMentionAvailable: self::isCopilotMentionAvailable(),
			aiFileTranscriptionAvailable: self::isAiFileTranscriptionAvailable(),
			chatSharingLinkAvailable: self::isChatSharingLinkAvailable(),
			isTasksRecentListAvailable: self::isTasksRecentListAvailable(),
			aiAssistantMcpSelectorAvailable: self::isAiAssistantMcpSelectorAvailable(),
			videoNoteTranscriptionAvailable: self::isVideoNoteTranscriptionAvailable(),
			isTextFormatToolbarAvailable: self::isTextFormatToolbarAvailable(),
			isCounterV3Available: self::isCounterV3Available(),
			isCopilotFileUploadAvailable: self::isCopilotFileUploadAvailable(),
			isBitrixGptUploadPolicyAvailable: self::isBitrixGptUploadPolicyAvailable(),
			isMountedTasksCardAvailable: self::isMountedTasksCardAvailable(),
			isBitrixGptV2Available: self::isBitrixGptV2Available(),
			isMessageBuilderAvailable: self::isMessageBuilderAvailable(),
			isAddingUserByMentionAvailable: self::isAddingUserByMentionAvailable(),
			isAddToChatPopupAvailable: self::isAddToChatPopupAvailable(),
			isExternalChatMessageForwardingAvailable: self::isExternalChatMessageForwardingAvailable(),
			isNestedChatAvailable: Collab::isNewProjectsAvailable(),
			isChatWithGuestsAvailable: self::isChatWithGuestsAvailable(GuestService::getInstance()->getCurrentInviterId()),
			isCopilotForceSearchAvailable: self::isCopilotForceSearchAvailable(),
			isCopilotWebSearchEnabledByAdmin: self::isCopilotWebSearchEnabledByAdmin(),
			isCopilotWebSearchAllowedByTariff: self::isCopilotWebSearchAllowedByTariff(),
			isAiAssistantFeedbackAvailable: self::isAiAssistantFeedbackAvailable(),
			isAiAssistantAgentModeAvailable: self::isAiAssistantAgentModeAvailable(),
			isChatFoldersWebAvailable: self::isChatFoldersWebAvailable(),
			isAiAssistantRegenerateAvailable: self::isAiAssistantRegenerateAvailable(),
			collabPreviewSourceEnabled: self::isCollabPreviewSourceEnabled(),
			isMarkdownAvailable: self::isMarkdownAvailable(),
			isCopilotDraftChatAvailable: self::isCopilotDraftChatAvailable(),
			isAttachChatToProjectAvailable: self::isAttachChatToProjectAvailable(),
			isAiTaskCreationFromMessagesAvailable: self::isAiTaskCreationFromMessagesAvailable(),
			isAirDesignEnabled: self::isAirDesignEnabled(),
			isMessageDateNavigationAvailable: self::isMessageDateNavigationAvailable(),
			isReplyWithMediaAvailable: self::isReplyWithMediaAvailable(),
			isTaskResultButtonAvailable: self::isTaskResultButtonAvailable(),
			isMessageFormBlockAvailable: self::isMessageFormBlockAvailable(),
			isBitrixGptScrollPinAvailable: self::isBitrixGptScrollPinAvailable(),
			isBitrixGptStopGenerationAvailable: self::isBitrixGptStopGenerationAvailable(),
			isChatBotCommandsAvailable: self::isChatBotCommandsAvailable(),
			isUploadCaptionMentionsFormatAvailable: self::isUploadCaptionMentionsFormatAvailable(),
			isVideoNotePlaybackRateAvailable: self::isVideoNotePlaybackRateAvailable(),
			isMentionInstantSearchAvailable: self::isMentionInstantSearchAvailable(),
			isTaskChatAutoUnmuteAvailable: self::isTaskChatAutoUnmuteAvailable(),
			isVibecodeAppOpenButtonAvailable: self::isVibecodeAppOpenButtonAvailable(),
		);
	}

	private static function isGiphyAvailable(): bool
	{
		return Loader::includeModule('imbot')
			&& method_exists(Giphy::class, 'isAvailable')
			&& Giphy::isAvailable()
		;
	}

	private static function isInviteByPhoneAvailable(): bool
	{
		return Loader::includeModule("bitrix24")
			&& Option::get('bitrix24', 'phone_invite_allowed', 'N') === 'Y'
		;
	}

	private static function isInviteByLinkAvailable(): bool
	{
		return true;
	}

	private static function isImOpenLinesV2Available(): bool
	{
		if (Loader::includeModule('imopenlines'))
		{
			return \Bitrix\ImOpenLines\V2\Settings\Settings::isV2Available();
		}

		return false;
	}

	private static function isVoteCreationAvailable(): bool
	{
		return Loader::includeModule('vote')
			&& class_exists('\\Bitrix\\Vote\\Config\\Feature')
			&& \Bitrix\Vote\Config\Feature::instance()->isImIntegrationEnabled()
		;
	}

	public static function isMessagesAutoDeleteEnabled(): bool
	{
		return Option::get('im', 'isAutoDeleteMessagesEnabled', 'Y') === 'Y';
	}

	public static function isDesktopRedirectAvailable(): bool
	{
		return Option::get('im', 'desktop_redirect_available', 'N') === 'Y';
	}

	public static function isAiAssistantAvailable(): bool
	{
		return ServiceLocator::getInstance()->get(AiAssistantService::class)->isAiAssistantAvailable();
	}

	public static function isCopilotMentionAvailable(): bool
	{
		if (!CopilotChat::isActive())
		{
			return false;
		}

		return true;
	}

	public static function isAiFileTranscriptionAvailable(): bool
	{
		return ServiceLocator::getInstance()->get(Restriction::class)->isTranscriptionActive();
	}

	public static function isChatSharingLinkAvailable(): bool
	{
		if (\CUserOptions::GetOption('im', 'chat_sharing_link_available_user', 'N') === 'Y')
		{
			return true;
		}

		return Option::get('im', 'chat_sharing_link_available', 'N') === 'Y';
	}

	public static function isVideoNoteTranscriptionAvailable(): bool
	{
		return ServiceLocator::getInstance()->get(Restriction::class)->isTranscriptionActive();
	}

	public static function isTasksRecentListAvailable(): bool
	{
		return Loader::includeModule('tasks');
	}

	public static function isAiAssistantMcpSelectorAvailable(): bool
	{
		if (self::isAiAssistantRestricted())
		{
			return false;
		}

		return Option::get('im', 'ai_assistant_mcp_selector_available', 'N') === 'Y';
	}

	public static function isTextFormatToolbarAvailable(): bool
	{
		return Option::get('im', 'text_format_toolbar_available', 'N') === 'Y';
	}

	public static function isCounterV3Available(): bool
	{
		return Option::get('im', 'counter_v3_available', 'N') === 'Y';
	}

	public static function isCopilotFileUploadAvailable(): bool
	{
		return ServiceLocator::getInstance()
			->get(AiAssistantService::class)
			->isBitrixGptV2Available('copilot_file_upload_available')
		;
	}

	public static function isBitrixGptUploadPolicyAvailable(): bool
	{
		return ServiceLocator::getInstance()
			->get(AiAssistantService::class)
			->isBitrixGptV2Available('bitrixgpt_upload_policy_available')
		;
	}

	public static function isMountedTasksCardAvailable(): bool
	{
		return Option::get('im', 'mounted_tasks_card_available', 'N') === 'Y';
	}

	public static function isBitrixGptV2Available(): bool
	{
		return ServiceLocator::getInstance()
			->get(AiAssistantService::class)
			->isBitrixGptV2Available('bitrixgpt_v2_available')
		;
	}

	public static function isBitrixGptStopGenerationAvailable(): bool
	{
		return ServiceLocator::getInstance()
			->get(AiAssistantService::class)
			->isBitrixGptV2Available('bitrixgpt_stop_generation_available')
		;
	}

	public static function isMessageBuilderAvailable(): bool
	{
		return Option::get('im', 'message_builder_available', 'N') === 'Y';
	}

	public static function isAddingUserByMentionAvailable(): bool
	{
		return Option::get('im', 'adding_user_by_mention_available', 'N') === 'Y';
	}

	public static function isAddToChatPopupAvailable(): bool
	{
		return Option::get('im', 'mention_add_to_chat_popup_available', 'N') === 'Y';
	}

	public static function isMessageDateNavigationAvailable(): bool
	{
		return Option::get('im', 'message_date_navigation_available', 'N') === 'Y';
	}

	public static function isExternalChatMessageForwardingAvailable(): bool
	{
		return Option::get('im', 'external_chat_message_forwarding_available', 'N') === 'Y';
	}

	public static function isChatWithGuestsAvailable(?int $inviterId = null): bool
	{
		if (Option::get('im', 'chat_with_guests_available', 'N') === 'Y')
		{
			return true;
		}

		return \CUserOptions::GetOption('im', 'chat_with_guests_available_user', 'N', $inviterId ?? false) === 'Y';
	}

	public static function isCopilotForceSearchAvailable(): bool
	{
		return ServiceLocator::getInstance()
			->get(AiAssistantService::class)
			->isBitrixGptV2Available('copilot_force_search_available')
		;
	}

	public static function isAiAssistantFeedbackAvailable(): bool
	{
		return Option::get('im', 'ai_assistant_feedback_available', 'N') === 'Y';
	}

	public static function isAiAssistantAgentModeAvailable(): bool
	{
		if (self::isAiAssistantRestricted())
		{
			return false;
		}

		return Option::get('im', 'ai_assistant_agent_mode_available', 'N') === 'Y';
	}

	private static function isAiAssistantRestricted(): bool
	{
		return Loader::includeModule('aiassistant')
			&& class_exists(AiAssistantRestriction::class)
			&& !AiAssistantRestriction::getInstance()->isAvailable();
	}

	public static function isChatFoldersWebAvailable(): bool
	{
		return Option::get('im', 'chat_folders_web_available', 'N') === 'Y';
	}

	public static function isVibecodeAppsFolderAvailable(): bool
	{
		return Option::get('im', 'vibecode_apps_folder_available', 'N') === 'Y';
	}

	public static function isVibecodeAppOpenButtonAvailable(): bool
	{
		return Option::get('im', 'vibecode_app_open_button_available', 'N') === 'Y';
	}

	public static function isCopilotWebSearchEnabledByAdmin(): bool
	{
		return ServiceLocator::getInstance()->get(WebSearchService::class)->isEnabledByAdmin();
	}

	public static function isCopilotWebSearchAllowedByTariff(): bool
	{
		return ServiceLocator::getInstance()->get(WebSearchService::class)->isAllowedByTariff();
	}

	public static function isAiAssistantRegenerateAvailable(): bool
	{
		return Option::get('im', 'ai_assistant_regenerate_available', 'N') === 'Y';
	}

	public static function isReplyWithMediaAvailable(): bool
	{
		return Option::get('im', 'reply_with_media_available', 'N') === 'Y';
	}

	public static function isTaskResultButtonAvailable(): bool
	{
		return Option::get('im', 'task_result_button_available', 'N') === 'Y';
	}

	public static function isMessageFormBlockAvailable(): bool
	{
		return Option::get('im', 'message_form_block_available', 'N') === 'Y';
	}

	public static function isBitrixGptScrollPinAvailable(): bool
	{
		return Option::get('im', 'bitrixgpt_scroll_pin_available', 'N') === 'Y';
	}

	public static function isCollabPreviewSourceEnabled(): bool
	{
		return Option::get('im', 'collab_preview_source_enabled', 'Y') === 'Y';
	}

	public static function isMarkdownAvailable(): bool
	{
		return Option::get('im', 'markdown_available', 'N') === 'Y';
	}

	public static function isCopilotDraftChatAvailable(): bool
	{
		return Option::get('im', 'copilot_draft_chat_available', 'N') === 'Y';
	}

	public static function isAttachChatToProjectAvailable(): bool
	{
		if (!Collab::isNewProjectsAvailable())
		{
			return false;
		}

		return Option::get('im', 'attach_chat_to_project_available', 'Y') === 'Y';
	}

	public static function isCopilotSimpleReasoningAvailable(): bool
	{
		return Option::get('im', 'copilot_simple_reasoning_available', 'N') === 'Y';
	}

	public static function isAiTaskCreationFromMessagesAvailable(): bool
	{
		if (Option::get('im', 'ai_task_creation_from_messages_available', 'N') !== 'Y')
		{
			return false;
		}

		return (new Restriction())->isAiTaskCreationActive();
	}

	public static function isAirDesignEnabled(): bool
	{
		return Option::get('im', 'air_design_enabled', 'N') === 'Y';
	}

	public static function isChatBotCommandsAvailable(): bool
	{
		return Option::get('im', 'chat_bot_commands_available', 'N') === 'Y';
	}

	public static function isUploadCaptionMentionsFormatAvailable(): bool
	{
		return Option::get('im', 'upload_caption_mentions_format_available', 'N') === 'Y';
	}

	public static function isVideoNotePlaybackRateAvailable(): bool
	{
		return Option::get('im', 'video_note_playback_rate_available', 'N') === 'Y';
	}

	public static function isMentionInstantSearchAvailable(): bool
	{
		return Option::get('im', 'mention_instant_search_available', 'N') === 'Y';
	}

	public static function isTaskChatAutoUnmuteAvailable(): bool
	{
		return Loader::includeModule('tasks')
			&& Option::get('tasks', 'task_chat_auto_unmute_available', 'N') === 'Y'
		;
	}
}
