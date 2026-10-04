<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Integration\ImBot;

use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\LaunchedTemplateRepository;
use Bitrix\Main\Application;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;

/**
 * Resolves the default avatar for an AI-agent chat bot by its launched template id.
 *
 * Precedence: a per-agent override (nodes/AI_AGENT/{systemCode}/avatar.png) wins over the
 * global default (imbot/install/avatar/aiagentbot/default.png). Returns null for templates that
 * are not launched copies of an AI_AGENT system node, so generic bizproc bots stay untouched.
 *
 * The template id is supplied explicitly by the caller (the bot-creating activity knows it via
 * CBPActivity::getWorkflowTemplateId()); it is never parsed out of the arbitrary bot code.
 *
 * @see \Bitrix\Bizproc\Integration\ImBot\BizprocBot
 * @see \Bitrix\ImBot\Bot\OpenLinesBizprocBot
 */
final class AiAgentBotAvatar
{
	private const GLOBAL_DEFAULT_PATH = '/bitrix/modules/imbot/install/avatar/aiagentbot/default.png';
	private const NODE_DIR_PATH = '/bitrix/modules/bizproc/nodes/AI_AGENT/';
	private const NODE_OVERRIDE_FILE = 'avatar.png';

	/**
	 * Absolute filesystem path to the default avatar for the launched template,
	 * or null when the template is not a launched copy of an AI_AGENT system node.
	 */
	public static function resolvePathByTemplateId(int $templateId): ?string
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$systemCode = (new LaunchedTemplateRepository())->getSystemCodeByTemplateId($templateId);
		// Guard against path traversal: an origin system code is always a plain node identifier.
		if (empty($systemCode) || !preg_match('/^[A-Za-z0-9_-]+$/', $systemCode))
		{
			return null;
		}

		$documentRoot = Application::getDocumentRoot();

		$nodeDir = $documentRoot . self::NODE_DIR_PATH . $systemCode;
		if (!Directory::isDirectoryExists($nodeDir))
		{
			return null;
		}

		$override = $nodeDir . '/' . self::NODE_OVERRIDE_FILE;
		if (File::isFileExists($override))
		{
			return $override;
		}

		$default = $documentRoot . self::GLOBAL_DEFAULT_PATH;
		if (File::isFileExists($default))
		{
			return $default;
		}

		return null;
	}

	/**
	 * File array (CFile::makeFileArray) for the create path (CUser::Add), or null.
	 */
	public static function getFileArrayByTemplateId(int $templateId): ?array
	{
		$path = self::resolvePathByTemplateId($templateId);

		return $path !== null ? \CFile::makeFileArray($path) : null;
	}

	/**
	 * Freshly saved b_file id for the update path (Bot::update accepts an int id only), or null.
	 *
	 * A new copy is saved on every call on purpose: sharing an id is unsafe because Bot::update
	 * deletes the previous avatar file.
	 */
	public static function getFileIdByTemplateId(int $templateId): ?int
	{
		$path = self::resolvePathByTemplateId($templateId);
		if ($path === null)
		{
			return null;
		}

		$fileId = (int)\CFile::saveFile(\CFile::makeFileArray($path), 'imbot');

		return $fileId > 0 ? $fileId : null;
	}
}
