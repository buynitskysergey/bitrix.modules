<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Vibecode;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Service\MicroService\BaseSender;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;

abstract class AbstractMicroserviceClient extends BaseSender
{
	public const MAX_RESPONSE_BODY_LENGTH = 2 * 1024 * 1024;

	private readonly EndpointResolver $endpoints;
	private ?int $lastHttpStatus = null;

	public function __construct(
		string $endpointUrl,
		private readonly ?HttpClient $httpClient = null,
	) {
		parent::__construct();
		$this->endpoints = new EndpointResolver($endpointUrl);
	}

	public function getLastHttpStatus(): ?int
	{
		return $this->lastHttpStatus;
	}

	protected function getServiceUrl(): string
	{
		return $this->endpoints->microservice();
	}

	protected function buildHttpClient(): HttpClient
	{
		$httpClient = $this->httpClient ?? parent::buildHttpClient();
		$httpClient->setPrivateIp(false);
		$httpClient->setRedirect(false);
		$httpClient->setBodyLengthMax(self::MAX_RESPONSE_BODY_LENGTH);

		return $httpClient;
	}

	public function getHttpClientParameters(): array
	{
		return array_replace(parent::getHttpClientParameters(), [
			'disableSslVerification' => false,
			'privateIp' => false,
			'redirect' => false,
			'bodyLengthMax' => self::MAX_RESPONSE_BODY_LENGTH,
		]);
	}

	protected function buildResult(HttpClient $httpClient, bool $requestResult): Result
	{
		$this->lastHttpStatus = (int)$httpClient->getStatus();

		return parent::buildResult($httpClient, $requestResult);
	}

	protected function createAnswerForJsonResponse($queryResult, $response, $errors, $status): Result
	{
		if (!$queryResult || (int)$status !== 200)
		{
			return parent::createAnswerForJsonResponse($queryResult, $response, $errors, $status);
		}

		if (!is_string($response) || $response === '')
		{
			return $this->protocolErrorResult();
		}

		try
		{
			$payload = Json::decode($response);
		}
		catch (\Throwable)
		{
			return $this->protocolErrorResult();
		}

		if (!is_array($payload) || !isset($payload['status']) || !is_string($payload['status']))
		{
			return $this->protocolErrorResult();
		}

		if ($payload['status'] === 'success')
		{
			if (!isset($payload['data']) || !is_array($payload['data']))
			{
				return $this->protocolErrorResult();
			}

			$result = new Result();
			$result->setData($payload['data']);

			return $result;
		}

		if ($payload['status'] !== 'error' || empty($payload['errors']) || !is_array($payload['errors']))
		{
			return $this->protocolErrorResult();
		}

		$result = new Result();
		foreach ($payload['errors'] as $error)
		{
			if (
				!is_array($error)
				|| !isset($error['code'], $error['message'])
				|| !is_string($error['code'])
				|| !is_string($error['message'])
				|| (isset($error['customData']) && !is_array($error['customData']))
			)
			{
				return $this->protocolErrorResult();
			}

			$result->addError(new Error(
				$error['message'],
				$error['code'],
				$error['customData'] ?? null,
			));
		}

		return $result;
	}

	private function protocolErrorResult(): Result
	{
		$result = new Result();
		$result->addError(new Error('Invalid Vibecode response', 'PROTOCOL_ERROR'));

		return $result;
	}
}
