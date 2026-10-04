<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Rest\File;

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;

final class RestIntegrationFactory
{
	private const REQUIRED_METHODS = [
		'Bitrix\\Rest\\V3\\Realisation\\Dto\\FileDto' => ['create'],
		'Bitrix\\Rest\\V3\\Realisation\\Dto\\UploadFileDto' => ['create', 'getResult', 'setResult'],
		'Bitrix\\Rest\\V3\\Realisation\\FileDtoProcessor' => ['processDto'],
		'CRestServer' => [
			'instance',
			'getAuth',
			'getAuthData',
			'getAuthType',
			'getScope',
			'getTokenCheckSignature',
			'getTransport',
		],
		'CRestUtil' => ['getDownloadUrl'],
	];

	/** @var callable(string): bool */
	private $moduleLoader;

	/** @var callable(string): bool */
	private $classExists;

	/** @var callable(string, string): bool */
	private $methodExists;

	public function __construct(
		?callable $moduleLoader = null,
		?callable $classExists = null,
		?callable $methodExists = null,
	)
	{
		$this->moduleLoader = $moduleLoader ?? static fn(string $moduleId): bool => Loader::includeModule($moduleId);
		$this->classExists = $classExists ?? static fn(string $class): bool => class_exists($class);
		$this->methodExists = $methodExists ?? static fn(string $class, string $method): bool => method_exists($class, $method);
	}

	public function requireFileContract(): void
	{
		if (!$this->isFileContractAvailable())
		{
			$this->throwUnavailable();
		}
	}

	public function isFileContractAvailable(): bool
	{
		try
		{
			if (!(($this->moduleLoader)('rest')))
			{
				return false;
			}

			foreach (self::REQUIRED_METHODS as $class => $methods)
			{
				if (!(($this->classExists)($class)))
				{
					return false;
				}

				foreach ($methods as $method)
				{
					if (!(($this->methodExists)($class, $method)))
					{
						return false;
					}
				}
			}

			return true;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function createScopedServer(\CRestServer $server): ScopedRestServer
	{
		$this->requireFileContract();

		return new ScopedRestServer($server);
	}

	public function createFileUrlBuilder(\CRestServer $server): FileUrlBuilder
	{
		return new FileUrlBuilder($this->createScopedServer($server));
	}

	private function throwUnavailable(): never
	{
		throw new SystemException('REST V3 file integration contract is unavailable.');
	}
}
