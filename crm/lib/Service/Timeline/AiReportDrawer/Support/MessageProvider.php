<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

use Bitrix\Main\Localization\Loc;

final class MessageProvider
{
	public static function getAssessmentAnchorTitle(): string
	{
		return Loc::getMessage('CRM_SERVICE_TIMELINE_AI_REPORT_DRAWER_MESSAGE_PROVIDER_ASSESSMENT_ANCHOR_TITLE') ?? '';
	}

	public static function getSummaryBlockTitle(): string
	{
		return Loc::getMessage('CRM_SERVICE_TIMELINE_AI_REPORT_DRAWER_MESSAGE_PROVIDER_SUMMARY_BLOCK_TITLE') ?? '';
	}

	public static function getLegacyAssessmentBlockTitle(): string
	{
		return Loc::getMessage('CRM_SERVICE_TIMELINE_AI_REPORT_DRAWER_MESSAGE_PROVIDER_LEGACY_ASSESSMENT_BLOCK_TITLE') ?? '';
	}

	public static function getTranscriptionBlockTitle(): string
	{
		return Loc::getMessage('CRM_SERVICE_TIMELINE_AI_REPORT_DRAWER_MESSAGE_PROVIDER_TRANSCRIPTION_BLOCK_TITLE') ?? '';
	}

	public static function getHiddenClientName(): string
	{
		return Loc::getMessage('CRM_SERVICE_TIMELINE_AI_REPORT_DRAWER_MESSAGE_PROVIDER_HIDDEN_CLIENT_NAME') ?? '';
	}

	public static function getOpenLinesGuestName(): string
	{
		return Loc::getMessage('CRM_SERVICE_TIMELINE_AI_REPORT_DRAWER_MESSAGE_PROVIDER_OPEN_LINES_GUEST_NAME') ?? '';
	}
}
