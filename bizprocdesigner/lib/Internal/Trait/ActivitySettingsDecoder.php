<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Trait;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Automation\Helper;
use Bitrix\Bizproc\Internal\Service\Document\DocumentsResolver;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

trait ActivitySettingsDecoder
{
	use TemplateDataDecoder;

	protected function decodeActivitySettings(array $request, array $documentType): array
	{
		Loader::requireModule('bizproc');

		[
			'template' => $template,
			'variables' => $variables,
			'parameters' => $parameters,
			'constants' => $constants,
		] = $this->decodeTemplateData($request);

		$properties = $request;
		unset(
			$properties['arWorkflowTemplate'],
			$properties['workflowTemplate'],
			$properties['arWorkflowParameters'],
			$properties['workflowParameters'],
			$properties['arWorkflowVariables'],
			$properties['workflowVariables'],
			$properties['arWorkflowConstants'],
			$properties['workflowConstants'],
		);
		$properties = Helper::unConvertProperties($properties, $documentType);

		return [
			'template' => $template,
			'parameters' => $parameters,
			'variables' => $variables,
			'constants' => $constants,
			'properties' => $properties,
		];
	}

	/**
	 * Document type nested in the client payload: it is accepted only when it resolves to a registered
	 * document entity, because it reaches Loader::includeModule() and the class autoloader as a module
	 * name and a class name. A rejected value is returned as a Result error rather than thrown:
	 * AbstractCommand::run() would turn an exception into a CommandException, and the refusal code
	 * would not reach the client.
	 *
	 * @return Result data: ['documentType' => ?array] - the normalised triple, or null when the payload
	 *   carries no nested type; ACCESS_DENIED when the value is present but not a registered document.
	 */
	protected function extractDocumentType(array $request): Result
	{
		// Called before decodeActivitySettings(), so the module cannot be assumed to be included yet.
		Loader::requireModule('bizproc');

		$result = new Result();
		$documentType = $request['documentType'] ?? null;

		if (\CBPHelper::isEmptyValue($documentType))
		{
			return $result->setData(['documentType' => null]);
		}

		// The resolver checks the value shape (non-empty entity and code, syntactically valid module id)
		// before DocumentEntityChecker includes the module and autoloads the class by the client name.
		$resolveResult = (new DocumentsResolver())->resolveFromPayload(['documentType' => $documentType]);
		$document = $resolveResult->isSuccess()
			? ($resolveResult->getDocuments()?->documents[0] ?? null)
			: null
		;
		// A registered document type always belongs to a module, while an empty module id passes the
		// resolver: parseDocumentId() validates the module only when it is non-empty and
		// DocumentEntityChecker then skips the include and only checks the class. A two-element value
		// normalises to that same shape, so the module is checked on the normalised triple.
		if ($document === null || $document->complexDocumentType->moduleId === '')
		{
			return $result->addError(ErrorMessage::ACCESS_DENIED->getCodedError());
		}

		return $result->setData(['documentType' => $document->complexDocumentType->toArray()]);
	}
}
