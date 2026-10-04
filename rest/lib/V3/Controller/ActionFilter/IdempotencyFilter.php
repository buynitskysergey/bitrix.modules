<?php

namespace Bitrix\Rest\V3\Controller\ActionFilter;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\IdempotencyKeyReusedException;
use Bitrix\Rest\V3\Idempotency\IdempotencyEntry;
use Bitrix\Rest\V3\Idempotency\IdempotencyKeyResolver;
use Bitrix\Rest\V3\Idempotency\IdempotencyReplayResponse;
use Bitrix\Rest\V3\Idempotency\IdempotencyService;
use Bitrix\Rest\V3\Interaction\Response\Response;

/**
 * REST V3 idempotency filter.
 *
 * Auto-detects write actions by signature (one parameter of AddRequest /
 * UpdateRequest / DeleteRequest) and honours #[Idempotent] / #[NotIdempotent]
 * overrides. When the client sends an Idempotency-Key header, the filter:
 *
 * - on cache miss: lets the action run and remembers the (key, bodyHash, ttl)
 *   so onAfterAction can persist the resulting response;
 * - on cache hit with matching body: cancels the action via EventResult::ERROR
 *   (without addError, to keep this a success path) and substitutes the cached
 *   response in onAfterAction;
 * - on cache hit with mismatched body: throws IdempotencyKeyReusedException.
 *
 * Wiring contract (enforced by RestController): the SAME instance must be
 * registered in both getDefaultPreFilters() and getDefaultPostFilters(), because
 * private state ($replayResponse / $pendingSave) must survive between the two
 * event phases. See spec design doc, Resolution 0.1.
 */
class IdempotencyFilter extends Base
{
	public const HEADER_NAME = 'Idempotency-Key';

	private ?IdempotencyReplayResponse $replayResponse = null;

	private ?array $pendingSave = null;

	private ?bool $enabled = null;

	private ?int $actionTtl = null;

	public function __construct
	(
		private readonly IdempotencyService $service,
		private readonly IdempotencyKeyResolver $resolver,
	)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event)
	{
		$action = $this->getAction();

		if ($this->enabled === null)
		{
			$config = $this->service->getActionConfig($action);
			$this->enabled = $config['apply'];
			$this->actionTtl = $config['ttl'];
		}

		$result = new EventResult(EventResult::SUCCESS, null, null, $this);

		if ($this->enabled === false)
		{
			return $result;
		}

		/** @var RestController $controller */
		$controller = $action->getController();

		$headerValue = $this->service->extractHeaderValue($controller->getRequest()->getHeaders());
		if ($headerValue === null)
		{
			return $result;
		}

		$actionUri = $controller->getServer()->getMethod();

		$clientId = $controller->getServer()->getClientId()
			?? $controller->getServer()->getPasswordId()
			?? $controller->getServer()->getAuthType()
			?? 'session';
		$userId = (string)(CurrentUser::get()->getId() ?? 'anon');

		$storageKey = $this->resolver->resolve(
			$clientId,
			$userId,
			$actionUri,
			$headerValue,
		);
		$bodyHash = hash('sha256', \Bitrix\Main\HttpRequest::getInput());

		$entry = $this->service->find($storageKey);

		if ($entry === null)
		{
			$this->pendingSave = [
				'key' => $storageKey,
				'bodyHash' => $bodyHash,
				'ttl' => $this->actionTtl ?? $this->service::DEFAULT_TTL,
			];
			$this->addResponseHeader($this->service::HEADER_NAME, $headerValue);

			return new EventResult(EventResult::SUCCESS, null, null, $this);
		}

		if ($entry->bodyHash !== $bodyHash)
		{
			throw new IdempotencyKeyReusedException();
		}

		$this->replayResponse = new IdempotencyReplayResponse(
			$entry->response,
			$entry->showRawData,
			$entry->showDebugInfo,
		);
		$this->addResponseHeader($this->service::HEADER_NAME, $headerValue);
		$this->addResponseHeader($this->service::REPLAY_HEADER_NAME, 'true');

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	public function onAfterAction(Event $event)
	{
		if ($this->replayResponse !== null)
		{
			$event->setParameter('result', $this->replayResponse);

			return;
		}

		if ($this->pendingSave === null)
		{
			return;
		}

		$result = $event->getParameter('result');

		if (!$result instanceof Response)
		{
			$this->pendingSave = null;

			return;
		}

		$statusCode = $this->resolveStatusCode($result);

		if ($statusCode === 200)
		{
			$entry = new IdempotencyEntry(
				bodyHash: $this->pendingSave['bodyHash'],
				statusCode: $statusCode,
				response: $result->toArray(),
				savedAt: new DateTime(),
				showRawData: $result->isShowRawData(),
				showDebugInfo: $result->isShowDebugInfo(),
			);
			$this->service->save($this->pendingSave['key'], $entry, $this->pendingSave['ttl']);
		}

		$this->pendingSave = null;
	}

	private function addResponseHeader(string $name, string $value): void
	{
		$response = Context::getCurrent()->getResponse();
		if ($response instanceof HttpResponse)
		{
			$response->addHeader($name, $value);
		}
	}

	private function resolveStatusCode(Response $result): int
	{
		if (!method_exists($result, 'getStatus'))
		{
			return 200;
		}

		$status = $result->getStatus();

		if (is_int($status))
		{
			return $status > 0 ? $status : 200;
		}

		if (is_string($status) && $status !== '')
		{
			$code = (int)$status;
			if ($code > 0)
			{
				return $code;
			}
		}

		return 200;
	}
}
