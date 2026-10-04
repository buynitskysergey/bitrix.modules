<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject\EffectiveConfiguration;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\ValueObject\ManagedAgentIdentity;
use Bitrix\Bizproc\Public\Command\AiAgent\Dto\SystemAiAgentActivationParameters;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Turns the values of a programmatic activation into the effective configuration of a managed instance.
 *
 * Only the names declared by the resolved source are accepted: an unknown name is rejected instead of being
 * silently ignored, and the rejection happens before any value is typed, so a name the caller invented can
 * never reach the standard extraction. A declared default is applied only when the key is absent, therefore
 * an explicitly passed null, false, zero or empty string stays as it is and goes through the usual type and
 * requirement check of business processes.
 *
 * Values are typed by the standard mechanism {@see \CBPWorkflowTemplateLoader::checkWorkflowParameters()},
 * the same one the manual start and the script runner use, so this API cannot drift away from what the
 * template really accepts. Once the values are effective, every section is measured as the serialized field
 * it will be written into, against the standard limit of that field.
 *
 * Nothing here is logged and no rejection carries a value or a name the caller passed: an error names the
 * section and the reason only, which is enough for the public command to pick its stable error code. The
 * fingerprint of the result is produced by {@see EffectiveConfiguration}, which also enforces the depth and
 * the canonical size of the caller data.
 *
 * Sections are never named one by one: the resolver walks the sections the source declares and the sections
 * {@see SystemAiAgentActivationParameters::getSections()} carries, so a section added later needs no change
 * of any signature.
 */
final class SystemAiAgentConfigurationResolver
{
	public const ERROR_INVALID_USER = 'AI_AGENT_ACTIVATION_INVALID_USER';

	public const ERROR_UNKNOWN_NAME = 'AI_AGENT_ACTIVATION_UNKNOWN_NAME';

	public const ERROR_REQUIRED_MISSING = 'AI_AGENT_ACTIVATION_REQUIRED_MISSING';

	public const ERROR_INVALID_VALUE = 'AI_AGENT_ACTIVATION_INVALID_VALUE';

	public const ERROR_LIMIT_EXCEEDED = 'AI_AGENT_ACTIVATION_LIMIT_EXCEEDED';

	public const ERROR_DECLARATIONS_INVALID = 'AI_AGENT_ACTIVATION_DECLARATIONS_INVALID';

	public const CUSTOM_DATA_SECTION = 'section';

	public const DATA_CONFIGURATION = 'configuration';

	public const DATA_FIELDS = 'fields';

	/**
	 * Error code the standard typing reports for a required value that stayed empty.
	 */
	private const REQUIRED_VALUE_CODE = 'RequiredValue';

	/**
	 * Builds the effective configuration of the activation, or fails with the stable codes of this class and
	 * the section in the custom data of every error.
	 *
	 * Successful data:
	 * <ul>
	 * <li> configuration: {@see EffectiveConfiguration}, effective values and the configuration fingerprint
	 * <li> fields: array&lt;string, array&gt;, payload of every serialized template field of the copy
	 * </ul>
	 *
	 * @param array $source data of {@see SystemAiAgentResolver::resolve()}
	 */
	public function resolve(
		ManagedAgentIdentity $identity,
		int $userId,
		SystemAiAgentActivationParameters $activation,
		array $source,
	): Result
	{
		if ($userId <= 0)
		{
			return (new Result())->addError(new Error('Launching user is not set', self::ERROR_INVALID_USER));
		}

		$sections = $source[SystemAiAgentResolver::DATA_SECTIONS];
		$requested = $activation->getSections();

		$result = self::rejectUnknownNames($sections, $requested);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$documentType = $source[SystemAiAgentResolver::DATA_DOCUMENT_TYPE];
		$effective = [];
		$declarations = [];
		$fields = [];

		foreach ($sections as $name => $section)
		{
			$sectionDeclarations = $section[SystemAiAgentResolver::SECTION_DECLARATIONS];
			$values = self::applyDefaults($sectionDeclarations, $requested[$name] ?? []);

			$typingErrors = [];
			$values = \CBPWorkflowTemplateLoader::checkWorkflowParameters(
				$sectionDeclarations,
				$values,
				$documentType,
				$typingErrors,
			);

			foreach (self::classifyTypingErrors($typingErrors) as $code)
			{
				$result->addError(self::sectionError($code, (string)$name));
			}

			$declarations[$name] = $sectionDeclarations;
			$effective[$name] = $values;
			$fields[$section[SystemAiAgentResolver::SECTION_FIELD]] = self::buildField($sectionDeclarations, $values);
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		// canonicalization runs before the fields are measured, so a value that cannot be represented at all
		// is rejected instead of being handed to the serializer of the template field
		try
		{
			$configuration = EffectiveConfiguration::create(
				identity: $identity,
				userId: $userId,
				installedRevision: (string)$source[SystemAiAgentResolver::DATA_INSTALLED_REVISION],
				declarations: $declarations,
				sections: $effective,
			);
		}
		catch (ArgumentException $exception)
		{
			return (new Result())->addError(self::configurationError($exception));
		}

		$result = self::checkSerializedLimits($sections, $fields);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return $result->setData([
			self::DATA_CONFIGURATION => $configuration,
			self::DATA_FIELDS => $fields,
		]);
	}

	/**
	 * Rejects every section that carries a name the source does not declare, including a whole section the
	 * source knows nothing about. Neither the unknown names nor their values are reported back.
	 *
	 * @param array<string, array> $sections
	 * @param array<string, array<string, mixed>> $requested
	 */
	private static function rejectUnknownNames(array $sections, array $requested): Result
	{
		$result = new Result();
		foreach ($requested as $name => $values)
		{
			$declarations = $sections[$name][SystemAiAgentResolver::SECTION_DECLARATIONS] ?? [];
			if (array_diff_key($values, $declarations) !== [])
			{
				$result->addError(self::sectionError(self::ERROR_UNKNOWN_NAME, (string)$name));
			}
		}

		return $result;
	}

	/**
	 * Values of the section with the declared defaults of the absent keys added.
	 *
	 * The default is taken from the normalized declaration, so it is found regardless of the letter case the
	 * template used for the key, and it is applied only when the key is absent.
	 *
	 * @param array<string, mixed> $declarations
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private static function applyDefaults(array $declarations, array $values): array
	{
		$prepared = [];
		foreach ($declarations as $name => $declaration)
		{
			if (array_key_exists($name, $values))
			{
				$prepared[$name] = $values[$name];

				continue;
			}

			$default = FieldType::normalizeProperty($declaration)['Default'];
			if ($default !== null)
			{
				$prepared[$name] = $default;
			}
		}

		return $prepared;
	}

	/**
	 * Stable codes of the reasons the standard typing reported, without its messages: those may name the
	 * declaration and, for some field types, the rejected value itself.
	 *
	 * @return list<string>
	 */
	private static function classifyTypingErrors(array $typingErrors): array
	{
		$required = false;
		$invalid = false;

		foreach ($typingErrors as $typingError)
		{
			if (($typingError['code'] ?? null) === self::REQUIRED_VALUE_CODE)
			{
				$required = true;
			}
			else
			{
				$invalid = true;
			}
		}

		$codes = [];
		if ($invalid)
		{
			$codes[] = self::ERROR_INVALID_VALUE;
		}
		if ($required)
		{
			$codes[] = self::ERROR_REQUIRED_MISSING;
		}

		return $codes;
	}

	/**
	 * Payload of the serialized template field: the declarations of the section with their effective values.
	 *
	 * @param array<string, mixed> $declarations
	 * @param array<string, mixed> $values
	 * @return array<string, array>
	 */
	private static function buildField(array $declarations, array $values): array
	{
		$field = [];
		foreach ($declarations as $name => $declaration)
		{
			$declaration['Default'] = $values[$name] ?? null;
			$field[$name] = $declaration;
		}

		return $field;
	}

	/**
	 * Checks that every section still fits the standard limit of its serialized field once the effective
	 * values are written into the copy.
	 *
	 * @param array<string, array> $sections
	 * @param array<string, array> $fields
	 */
	private static function checkSerializedLimits(array $sections, array $fields): Result
	{
		$result = new Result();
		foreach ($sections as $name => $section)
		{
			$field = $fields[$section[SystemAiAgentResolver::SECTION_FIELD]];
			if ($field === [])
			{
				continue;
			}

			$length = \CBPWorkflowTemplateLoader::getCompressedFieldLength($field);
			if ($length === false || $length > $section[SystemAiAgentResolver::SECTION_MAX_SERIALIZED_LENGTH])
			{
				$result->addError(self::sectionError(self::ERROR_LIMIT_EXCEEDED, (string)$name));
			}
		}

		return $result;
	}

	/**
	 * Maps a rejection of {@see EffectiveConfiguration} to a stable code: the parameter of the exception
	 * tells whether the declarations of the source or a section of the caller could not be canonicalized,
	 * and the class of the exception tells a broken value from an exceeded depth or size limit.
	 */
	private static function configurationError(ArgumentException $exception): Error
	{
		$parameter = (string)$exception->getParameter();
		if ($parameter === EffectiveConfiguration::DECLARATIONS_PARAMETER)
		{
			return new Error('Activation declarations of the source are not usable', self::ERROR_DECLARATIONS_INVALID);
		}

		$code = $exception instanceof ArgumentOutOfRangeException
			? self::ERROR_LIMIT_EXCEEDED
			: self::ERROR_INVALID_VALUE
		;

		return self::sectionError($code, self::sectionOfParameter($parameter));
	}

	private static function sectionOfParameter(string $parameter): string
	{
		return str_starts_with($parameter, EffectiveConfiguration::SECTION_PARAMETER_PREFIX)
			? substr($parameter, strlen(EffectiveConfiguration::SECTION_PARAMETER_PREFIX))
			: $parameter
		;
	}

	private static function sectionError(string $code, string $section): Error
	{
		return new Error('Activation section is not accepted', $code, [self::CUSTOM_DATA_SECTION => $section]);
	}
}
