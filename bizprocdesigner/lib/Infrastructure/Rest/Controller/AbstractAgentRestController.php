<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Controller;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\ActionFilter\AgentTokenFilter;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\ActionFilter\AiAvailabilityFilter;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Service\UnconvertibleGraphReader;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\WorkflowTemplateIdentifier;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\AgentWorkflowValidationResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentTokenGuard;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DocumentAccessService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\FramePostLayoutChecker;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\GraphLimitsValidator;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\SaveWorkflowValidator;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Controller\ActionFilter\IdempotencyFilter;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

abstract class AbstractAgentRestController extends RestController
{
	/**
	 * The checks of the contour stand before idempotency, so a revoked token is refused with 403 on every
	 * path a call to an action of the contour can take - including the two where the action never executes
	 * {@see AgentTokenFilter}.
	 *
	 * The idempotency filter is moved rather than rebuilt: the same instance serves the pre- and the
	 * post-chain, because the state it sets before the action has to survive to after it. Its position in
	 * the parent list is found by type, not assumed.
	 */
	protected function getDefaultPreFilters(): array
	{
		$inheritedFilters = [];
		$idempotencyFilters = [];
		foreach (parent::getDefaultPreFilters() as $filter)
		{
			if ($filter instanceof IdempotencyFilter)
			{
				$idempotencyFilters[] = $filter;
			}
			else
			{
				$inheritedFilters[] = $filter;
			}
		}

		return [
			...$inheritedFilters,
			new AiAvailabilityFilter(),
			new AgentTokenFilter(),
			...$idempotencyFilters,
		];
	}

	/**
	 * The binding is verified before any lookup, so a template the token is not bound to is refused the same
	 * whether it exists or not: a 404 against a 403 would let a caller probe for foreign templates.
	 *
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 */
	protected function resolveAndAuthorize(int $templateId): WorkflowTemplateIdentifier
	{
		$this->assertTokenValidForTemplate($templateId);

		return $this->manageableTemplate($templateId);
	}

	/**
	 * For actions that carry no templateId of their own. No assertTokenValidForTemplate() here: reading the
	 * binding answers for an active, non-expired token of ours and is itself the fresh check.
	 *
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 */
	protected function resolveAndAuthorizeBoundTemplate(): WorkflowTemplateIdentifier
	{
		$passwordId = $this->getServer()?->getPasswordId();
		$boundTemplateId = AgentTokenGuard::forCurrentRequest()->getBoundTemplateId($passwordId);
		if ($boundTemplateId === null)
		{
			throw new AccessDeniedException();
		}

		return $this->manageableTemplate($boundTemplateId);
	}

	/**
	 * Judging the authorizing token is the caller's part, done before anything is looked up.
	 *
	 * @throws AccessDeniedException
	 * @throws EntityNotFoundException
	 */
	private function manageableTemplate(int $templateId): WorkflowTemplateIdentifier
	{
		$userId = $this->currentUserId();

		$identifier = Container::getAgentWorkflowResolverService()->resolveByTemplateId($templateId, $userId);
		if ($identifier === null)
		{
			throw new EntityNotFoundException($templateId);
		}

		$isUserAdmin = (new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser))->isAdmin();
		if (!(new DocumentAccessService())->canManageDocument($userId, $identifier->documentDescription, $templateId, $isUserAdmin))
		{
			throw new AccessDeniedException();
		}

		return $identifier;
	}

	/**
	 * Fresh re-validation of the authorizing token against the requested template: the auth chain of rest
	 * caches validity for up to a day, so a revoked token can pass transport auth and still reach here
	 * {@see AgentTokenGuard}. Comparing the requested template against the binding closes tampering with it.
	 *
	 * @throws AccessDeniedException
	 */
	protected function assertTokenValidForTemplate(int $templateId): void
	{
		$passwordId = $this->getServer()?->getPasswordId();
		if (!AgentTokenGuard::forCurrentRequest()->isValidForTemplate($passwordId, $templateId))
		{
			throw new AccessDeniedException();
		}
	}

	protected function currentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	/**
	 * The fields the caller asked for, structured: nested ones live in the nested structures of the
	 * select, so reading it as a flat list would drop them without a word.
	 */
	protected function selectedFields(?SelectStructure $select): array
	{
		return $select?->getStructuredList() ?? [];
	}

	/**
	 * Header of the template resource, filled the same way wherever the resource is answered with, so the
	 * listing, the read and an added draft share one shape. A key names the dto property it fills.
	 *
	 * A template with no date of modification answers with null rather than with the empty string
	 * CRestUtil::convertDateTime() renders an absent date as: that reads as a date the caller failed to parse.
	 *
	 * @return array{
	 *     id: int,
	 *     name: string,
	 *     documentType: DocumentDescription,
	 *     modified: ?string,
	 *     hasDraft: bool,
	 * }
	 */
	protected function resourceHeader(WorkflowTemplateIdentifier $identifier): array
	{
		return [
			'id' => (int)$identifier->templateId,
			'name' => (string)$identifier->name,
			'documentType' => $identifier->documentDescription,
			'modified' => $identifier->modified === null
				? null
				: \CRestUtil::convertDateTime($identifier->modified),
			'hasDraft' => $identifier->draftId !== null,
		];
	}

	/**
	 * The header plus the graph the identifier points at, so a read template and an added draft answer with
	 * one shape. Null when the graph is gone.
	 *
	 * @return array{
	 *     id: int,
	 *     name: string,
	 *     documentType: DocumentDescription,
	 *     modified: ?string,
	 *     hasDraft: bool,
	 *     draftId: ?int,
	 *     blocks: AgentBlockCollection,
	 *     connections: AgentConnectionCollection,
	 * }|null
	 */
	protected function templateResource(WorkflowTemplateIdentifier $identifier): ?array
	{
		$agentTemplate = Container::getAgentWorkflowResolverService()->getAgentTemplate($identifier);
		if ($agentTemplate === null)
		{
			return null;
		}

		return [
			...$this->resourceHeader($identifier),
			'draftId' => $identifier->draftId,
			'blocks' => $agentTemplate->blocks,
			'connections' => $agentTemplate->connections,
		];
	}

	/**
	 * The submitted graph in the plain shape the domain validators read. Going through the dto first is
	 * what makes an unknown or a read-only field of the resource a form error instead of a silently
	 * ignored key.
	 *
	 * A form the conversion cannot read at all is read without it, with the refusals of the conversion
	 * raised by hand, so a graph is refused the same whichever path read the body
	 * {@see UnconvertibleGraphReader}. The reader is given the dto of this controller; the structure holds
	 * its own copy of the same class.
	 *
	 * @return array{blocks: mixed, connections: mixed} whatever the caller sent, judged by the validators
	 *
	 * @throws \Bitrix\Rest\V3\Exception\UnknownDtoPropertyException
	 * @throws \Bitrix\Rest\V3\Exception\Validation\DtoValidationException
	 * @throws \Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException
	 */
	protected function submittedGraph(FieldsStructure $fields): array
	{
		$submitted = UnconvertibleGraphReader::plainFields($fields->getItems(), $this->resolveDtoClass())
			?? self::plainValue($fields->convertToDto('add'));

		return [
			'blocks' => $submitted['blocks'] ?? null,
			'connections' => $submitted['connections'] ?? null,
		];
	}

	/**
	 * The blocks and the connections as the caller wrote them: what the bounds are measured on and what the
	 * addresses of their problems count positions in.
	 *
	 * @return array{blocks: mixed, connections: mixed}
	 */
	protected function rawGraph(FieldsStructure $fields): array
	{
		$items = $fields->getItems();

		return [
			'blocks' => $items['blocks'] ?? null,
			'connections' => $items['connections'] ?? null,
		];
	}

	/**
	 * The bounds of the graph, judged on the raw body before the conversion: walking it costs milliseconds
	 * where converting a graph of that size costs seconds. The domain checks the same bounds again
	 * {@see GraphLimitsValidator}.
	 *
	 * Called from the body of an action, after the token and the document access have been judged: raised
	 * earlier it would answer a revoked token with 400 instead of 403. A Result rather than a throw, because
	 * template.validate answers a graph it rejects with a report and the status 200.
	 */
	protected function validateGraphLimits(array $rawGraph): Result
	{
		return (new GraphLimitsValidator())->validate(
			blocks: $rawGraph['blocks'],
			connections: $rawGraph['connections'],
			source: RequestSource::Rest,
		);
	}

	/**
	 * The verdict on a submitted graph, shared by the dry run and the write so both answer with the same
	 * one. Returns the accepted graph as AgentWorkflowValidationResult, or a Result carrying the problems.
	 */
	protected function validateGraph(array $graph, DocumentDescription $documentType): Result
	{
		$validateResult = (new SaveWorkflowValidator(
			input: $graph,
			documentType: $documentType,
			source: RequestSource::Rest,
		))->validate();

		if (!$validateResult instanceof AgentWorkflowValidationResult)
		{
			return $validateResult;
		}

		// Frame geometry is server-computed after layout, so a frame capturing a non-member or two frames
		// intersecting can only be seen here, after validation and before persist.
		$postLayoutResult = (new FramePostLayoutChecker())->check(
			$validateResult->blocks,
			$validateResult->connections,
		);

		return $postLayoutResult->isSuccess() ? $validateResult : $postLayoutResult;
	}

	/**
	 * A dto tree turned into plain arrays. Not Dto::toArray(): it re-reads the class through reflection once
	 * per field, which on a graph at the limit of the validators costs more than the validation it feeds.
	 */
	private static function plainValue(mixed $value): mixed
	{
		if ($value instanceof Dto)
		{
			return array_map(self::plainValue(...), get_object_vars($value));
		}

		if ($value instanceof DtoCollection)
		{
			$items = [];
			foreach ($value as $item)
			{
				$items[] = self::plainValue($item);
			}

			return $items;
		}

		return is_array($value) ? array_map(self::plainValue(...), $value) : $value;
	}
}
