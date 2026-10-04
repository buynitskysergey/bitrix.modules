<?php

namespace Bitrix\Crm\Integration\AI;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Text\HtmlFilter;

final class AiMessageProvider
{
	private const JOB_LANGUAGE_DETAILS_ARTICLE_CODE = 20423978;
	private const CALL_QUALITY_DISCLAIMER_ARTICLE_CODE = 20412666;

	public static function getJobLanguageMessageData(string $languageTitle): ?array
	{
		$template = Loc::getMessage('CRM_AI_MESSAGE_PROVIDER_JOB_LANGUAGE_MESSAGE');
		$detailsLinkTitle = Loc::getMessage('CRM_AI_MESSAGE_PROVIDER_HELPDESK_LINK_TITLE');
		if ($template === null || $detailsLinkTitle === null)
		{
			return null;
		}

		$languageTitle = mb_strtolower($languageTitle);
		$copilotName = AIManager::getCopilotName();

		return [
			'textTemplate' => $template,
			'detailsLinkTitle' => $detailsLinkTitle,
			'detailsLinkArticleCode' => self::JOB_LANGUAGE_DETAILS_ARTICLE_CODE,
			'languageTitle' => $languageTitle,
			'copilotName' => $copilotName,
			'html' => Loc::getMessage(
				'CRM_AI_MESSAGE_PROVIDER_JOB_LANGUAGE_MESSAGE',
				[
					'#COPILOT_NAME#' => HtmlFilter::encode($copilotName),
					'#LANGUAGE_TITLE#' => HtmlFilter::encode($languageTitle),
					'#DETAILS_LINK#' => self::buildHelpdeskLinkFull(
						self::JOB_LANGUAGE_DETAILS_ARTICLE_CODE,
						$detailsLinkTitle,
					),
				],
			),
		];
	}

	public static function getAiDisclaimerData(): array
	{
		$articleCode = self::CALL_QUALITY_DISCLAIMER_ARTICLE_CODE;
		$copilotName = AIManager::getCopilotName();
		$template = Loc::getMessage('CRM_AI_MESSAGE_PROVIDER_AI_DISCLAIMER_MESSAGE') ?? '';

		return [
			'textTemplate' => $template,
			'articleCode' => $articleCode,
			'copilotName' => $copilotName,
			'html' => Loc::getMessage(
				'CRM_AI_MESSAGE_PROVIDER_AI_DISCLAIMER_MESSAGE',
				[
					'#COPILOT_NAME#' => HtmlFilter::encode($copilotName),
					'#LINK_START#' => self::buildHelpdeskLinkStart($articleCode),
					'#LINK_END#' => self::buildHelpdeskLinkEnd(),
				],
			) ?? '',
		];
	}

	public static function getLanguageTitleByLanguageId(?string $languageId): ?string
	{
		if (!is_string($languageId) || $languageId === '')
		{
			return null;
		}

		return AIManager::getAvailableLanguageList()[$languageId] ?? null;
	}

	private static function buildHelpdeskLinkFull(int $articleCode, string $title): string
	{
		$title = HtmlFilter::encode(\CUtil::JSEscape($title));

		return sprintf(
			'<span onclick="top.BX.Helper.show(\'redirect=detail&code=%d\');">
				%s
			</span>',
			$articleCode,
			$title,
		);
	}

	private static function buildHelpdeskLinkStart(int $articleCode): string
	{
		return sprintf(
			'<span onclick="top.BX.Helper.show(\'redirect=detail&code=%d\');">',
			$articleCode,
		);
	}

	private static function buildHelpdeskLinkEnd(): string
	{
		return '</span>';
	}
}
