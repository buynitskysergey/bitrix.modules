<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\AiAgent\Enum;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Stable reason why a system AI agent lifecycle operation was rejected.
 *
 * Backing values are the codes of the errors carried by the result of a command, so a calling module branches
 * on the code and never on the message. A code added later does not break an existing caller: an unknown code
 * reaches it as a failed result whose reason it simply does not recognize.
 *
 * Neither the message nor the error data carries anything of the caller: values, the context id, the
 * parameters, the constants, the whole DTO and the text of an internal exception stay inside the module.
 */
enum SystemAiAgentErrorCode: string
{
	case AgentNotAvailable = 'AI_AGENT_NOT_AVAILABLE';
	case InvalidContext = 'AI_AGENT_INVALID_CONTEXT';
	case InvalidUser = 'AI_AGENT_INVALID_USER';
	case UnknownParameter = 'AI_AGENT_UNKNOWN_PARAMETER';
	case MissingRequiredParameter = 'AI_AGENT_REQUIRED_PARAMETER_MISSING';
	case InvalidParameter = 'AI_AGENT_INVALID_PARAMETER';
	case UnknownConstant = 'AI_AGENT_UNKNOWN_CONSTANT';
	case MissingRequiredConstant = 'AI_AGENT_REQUIRED_CONSTANT_MISSING';
	case InvalidConstant = 'AI_AGENT_INVALID_CONSTANT';
	case ConfigurationConflict = 'AI_AGENT_CONFIGURATION_CONFLICT';
	case OperationConflict = 'AI_AGENT_OPERATION_CONFLICT';
	case OperationFailed = 'AI_AGENT_OPERATION_FAILED';
	case UnsupportedResource = 'AI_AGENT_UNSUPPORTED_RESOURCE';
	case EnableFailed = 'AI_AGENT_ENABLE_FAILED';
	case CleanupPending = 'AI_AGENT_CLEANUP_PENDING';

	/**
	 * Returns the description of the reason for the developer of the calling module.
	 *
	 * @return string
	 */
	public function getMessage(): string
	{
		return (string)Loc::getMessage($this->getPhraseId());
	}

	/**
	 * Builds the public error of this code.
	 *
	 * The error carries the description and the code only: custom data would be the one place where values of
	 * the caller could leak into a public contract.
	 *
	 * @return Error
	 */
	public function createError(): Error
	{
		return new Error($this->getMessage(), $this->value);
	}

	private function getPhraseId(): string
	{
		return match ($this)
		{
			self::AgentNotAvailable => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_NOT_AVAILABLE',
			self::InvalidContext => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_INVALID_CONTEXT',
			self::InvalidUser => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_INVALID_USER',
			self::UnknownParameter => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_UNKNOWN_PARAMETER',
			self::MissingRequiredParameter => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_REQUIRED_PARAMETER_MISSING',
			self::InvalidParameter => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_INVALID_PARAMETER',
			self::UnknownConstant => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_UNKNOWN_CONSTANT',
			self::MissingRequiredConstant => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_REQUIRED_CONSTANT_MISSING',
			self::InvalidConstant => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_INVALID_CONSTANT',
			self::ConfigurationConflict => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_CONFIGURATION_CONFLICT',
			self::OperationConflict => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_OPERATION_CONFLICT',
			self::OperationFailed => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_OPERATION_FAILED',
			self::UnsupportedResource => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_UNSUPPORTED_RESOURCE',
			self::EnableFailed => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_ENABLE_FAILED',
			self::CleanupPending => 'BIZPROC_PUBLIC_COMMAND_AI_AGENT_ERROR_CLEANUP_PENDING',
		};
	}
}
