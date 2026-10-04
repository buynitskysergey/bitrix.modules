<?php

namespace Bitrix\Rest\V3;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\Internal\Model\DeferredBatch\Params;
use Bitrix\Rest\V3\Interaction\Request\ServerRequest;
use Bitrix\Rest\V3\Interaction\Response\Response;
use Bitrix\Rest\V3\Schema\Scope;
use CRestApiServer;
use CRestUtil;

/**
 * REST API server variant for deferred (worker) batch execution.
 *
 * Bypasses HTTP auth chain and sets up user / scopes from stored data.
 * Implements {@see DeferredModeAwareInterface} so OrmRepository disables
 * the default 50-record pagination limit.
 */
final class DeferredRestApiServer extends CRestApiServer implements DeferredModeAwareInterface
{
	public function isDeferredMode(): bool
	{
		return true;
	}

	protected function initRequestScope(
		ServerRequest $request,
		\Bitrix\Rest\V3\Schema\MethodDescription $methodDescription,
	): void
	{
	}

}


