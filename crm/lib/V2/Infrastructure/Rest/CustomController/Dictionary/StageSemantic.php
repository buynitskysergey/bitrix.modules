<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\StageSemanticDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary\StageSemanticListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Dictionary\StageSemanticNotFoundException;
use Bitrix\Crm\V2\Public\Provider\Dictionary\StageSemanticProvider;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(StageSemanticDto::class)]
final class StageSemantic extends RestController
{
	private ?StageSemanticProvider $provider = null;

	public function getAction(GetRequest $request): GetResponse
	{
		$semantic = $this->getProvider()->getById($request->id, $this->getResponseLanguage());
		if ($semantic === null)
		{
			throw new StageSemanticNotFoundException($request->id);
		}

		return new GetResponse(
			$this->getDtoMapper()->mapOne($semantic, $request->select?->getStructuredList() ?? []),
		);
	}

	public function listAction(ListRequest $request): ListResponse
	{
		$semantics = (new StageSemanticListRequestMapper())->map(
			$this->getProvider()->getList($this->getResponseLanguage()),
			$request,
		);

		return new ListResponse(
			$this->getDtoMapper()->mapCollection($semantics, $request->select?->getStructuredList() ?? []),
		);
	}

	private function getProvider(): StageSemanticProvider
	{
		return $this->provider ??= new StageSemanticProvider();
	}
}
