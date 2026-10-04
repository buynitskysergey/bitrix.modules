<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\Document;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\AbstractAgentRestController;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\DocumentFieldDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Request\DocumentFieldListRequest;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DocumentFieldService;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * REST V3 controller for the fields of the document the bound template runs on.
 *
 * bizprocdesigner.document.field.list - fields the agent can address in a workflow graph.
 */
#[DtoType(DocumentFieldDto::class)]
final class Field extends AbstractAgentRestController
{
	/**
	 * Returns the document fields of the template the calling token is bound to.
	 *
	 * Whether a search phrase is answered by the semantic search or by the full field set is the
	 * service's call, not the transport's.
	 *
	 * @restMethod bizprocdesigner.document.field.list
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 * @throws LoaderException
	 * @throws ArgumentException
	 */
	public function listAction(DocumentFieldListRequest $request): ListResponse
	{
		$identifier = $this->resolveAndAuthorizeBoundTemplate();

		$fields = (new DocumentFieldService())->findFields(
			$identifier->documentDescription,
			$request->search ?? '',
		);

		return new ListResponse(
			$this->getDtoMapper()->mapCollection(
				$fields === null ? [] : iterator_to_array($fields),
				$this->selectedFields($request->select),
			),
		);
	}
}
