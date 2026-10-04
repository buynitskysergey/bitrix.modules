<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Rest\V3\Data\OrmRepository;
use Bitrix\Rest\V3\Interaction\Request\Request;

trait OrmActionTrait
{
	public function getOrmRepositoryByRequest(Request $request): OrmRepository
	{
		$repo = new OrmRepository($request->getDtoClass());

		if ($this->isOrmDeferredMode())
		{
			$repo->setDeferredMode(true);
		}

		return $repo;
	}

	protected function isOrmDeferredMode(): bool
	{
		return false;
	}
}
