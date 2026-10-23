<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\ClassLocator;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Enabled;
use Bitrix\Rest\V3\Attribute\McpTool;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\CacheManager;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Documentation\Attributes\Deprecated;
use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\Generator;
use Bitrix\Rest\V3\Dto\TypeHelper;
use Bitrix\Rest\V3\Exception\TooManyAttributesException;
use Bitrix\Rest\V3\Mcp\McpToolMetadata;
use Bitrix\Rest\V3\Interaction\Response\Response;
use Bitrix\Rest\V3\Realisation\Controller\Field;
use Bitrix\Rest\V3\Realisation\Controller\Field\Custom;
use Bitrix\Rest\V3\Realisation\Dto\DtoFieldDto;
use Bitrix\Rest\V3\Realisation\Dto\Field\Custom\EnumDto;
use Bitrix\Rest\V3\Realisation\Dto\Field\CustomDto;
use ReflectionClass;
use ReflectionMethod;

final class ControllerDataManager
{
	private const CONTROLLERS_DATA_CACHE_KEY = 'rest.v3.ControllerDataManager.controllersData.cache.key';

	private const GENERATED_DTO_CACHE_KEY = 'rest.v3.ControllerDataManager.generatedDto.cache.key';

	private static array $byName;

	private static array $byModule;

	private array $systemControllersData = [];

	private array $reflections = [];

	private array $dtos = [];

	public function getData()
	{
		if (isset(self::$byName) && isset(self::$byModule))
		{
			return [
				'byName' => self::$byName,
				'byModule' => self::$byModule,
			];
		}

		$items = CacheManager::get(self::CONTROLLERS_DATA_CACHE_KEY);
		if ($items !== null)
		{
			foreach ($items as $controllerCacheData)
			{
				if (!Loader::includeModule($controllerCacheData['module']))
				{
					continue;
				}

				$controllerData = ControllerData::fromArray($controllerCacheData);
				self::$byName[$controllerData->controllerFqcn] = $controllerData;
				self::$byModule[$controllerData->module][$controllerData->controllerFqcn] = $controllerData;
			}
		}
		else
		{
			$controllersCacheData = [];

			$this->systemControllersData[Field::class] = new ControllerData(
				module: 'rest',
				controllerFqcn: Field::class,
				dtoFqcn: DtoFieldDto::class,
				namespace: 'Bitrix\Rest\V3\Realisation\Controller',
				enabled: true,
			);

			$this->systemControllersData[Custom::class] = new ControllerData(
				module: 'rest',
				controllerFqcn: Custom::class,
				dtoFqcn: CustomDto::class,
				namespace: 'Bitrix\Rest\V3\Realisation\Controller',
				enabled: true,
			);

			$this->systemControllersData[Custom\Enum::class] = new ControllerData(
				module: 'rest',
				controllerFqcn: Custom\Enum::class,
				dtoFqcn: EnumDto::class,
				namespace: 'Bitrix\Rest\V3\Realisation\Controller',
				enabled: true,
			);

			$modulesConfig = ServiceLocator::getInstance()->get(ModuleManager::class)->getConfigs();
			foreach ($modulesConfig as $moduleId => $moduleConfig)
			{
				$generatedDtoCacheData = [];
				if (!Loader::includeModule($moduleId))
				{
					continue;
				}

				$namespaces = array_merge(
					[$moduleConfig->defaultNamespace],
					$moduleConfig->namespaces,
				);

				$customControllerData = [];
				$discoveredControllersData = [];

				if (
					$moduleConfig->schemaProviderClass !== null
					&& class_exists($moduleConfig->schemaProviderClass)
					&& is_subclass_of($moduleConfig->schemaProviderClass, SchemaProvider::class)
				) {
					$schemaProvider = new ($moduleConfig->schemaProviderClass);

					foreach ($schemaProvider->getDataForDtoGeneration() as $generatedDto)
					{
						if (!$generatedDto instanceof GeneratedDto)
						{
							throw new \InvalidArgumentException('SchemaProvider::getDataForDtoGeneration must return array of GeneratedDto instances.');
						}

						Generator::generateByDto($generatedDto);
						$generatedDtoCacheData[] = $generatedDto;
					}

					foreach ($schemaProvider->getControllersData() as $controllerData)
					{
						$customControllerData[$controllerData->controllerFqcn] = $controllerData;
					}
				}

				foreach ($namespaces as $namespace)
				{
					$classes = ClassLocator::getClassesByNamespace($namespace);
					foreach ($classes as $controllerClass)
					{
						$controllerReflection = new ReflectionClass($controllerClass);
						if (!$controllerReflection->isSubclassOf(RestController::class))
						{
							continue;
						}

						$dtoClass = $this->getDtoClassFromAttributes($controllerReflection);
						$isEnabled = $this->resolveControllerEnabled($controllerReflection);
						$providerControllerData = $customControllerData[$controllerClass] ?? null;

						$controllerData = $this->createDiscoveredControllerData(
							module: $moduleId,
							controllerFqcn: $controllerClass,
							namespace: $namespace,
							dtoFqcn: $dtoClass,
							isEnabled: $isEnabled,
							providerControllerData: $providerControllerData,
						);

						$this->addMethodDescriptionsByControllerReflection($controllerData, $controllerReflection);

						if ($providerControllerData !== null)
						{
							foreach ($providerControllerData->getMethods() as $customMethodDescription)
							{
								$controllerData->addMethod($customMethodDescription);
							}
							unset($customControllerData[$controllerClass]);
						}

						$discoveredControllersData[$controllerReflection->getName()] = $controllerData;
					}
				}

				// generated field methods must not shadow routes another controller of the module declares itself
				$declaredActionUris = $this->collectDeclaredActionUris(
					array_merge($discoveredControllersData, $customControllerData),
				);

				foreach ($discoveredControllersData as $controllerName => $controllerData)
				{
					$dto = $this->getDtoByClass($controllerData->dtoFqcn);
					if ($dto !== null)
					{
						$this->addDtoFieldMethods($dto, $controllerData, $declaredActionUris);
					}

					$this->registerControllerData($controllerName, $controllerData, $controllersCacheData);
				}

				foreach ($customControllerData as $controllerData)
				{
					$this->addDtoFieldMethodsForCustomController($controllerData, $declaredActionUris);

					$this->registerControllerData($controllerData->controllerFqcn, $controllerData, $controllersCacheData);
				}

				$this->saveGeneratedDtosByModuleId($moduleId, $generatedDtoCacheData);
			}

			foreach ($this->systemControllersData as $systemControllerData)
			{
				$systemControllerCacheData = $systemControllerData->toArray();

				CacheManager::set($this->getControllerCacheKey($systemControllerData->controllerFqcn), $systemControllerCacheData, CacheManager::ONE_HOUR_TTL);
				$controllersCacheData[$systemControllerData->controllerFqcn] = $systemControllerCacheData;

				self::$byName[$systemControllerData->controllerFqcn] = $systemControllerData;
				self::$byModule[$systemControllerData->module][$systemControllerData->controllerFqcn] = $systemControllerData;
			}

			CacheManager::set(self::CONTROLLERS_DATA_CACHE_KEY, $controllersCacheData, CacheManager::ONE_HOUR_TTL);
		}

		return [
			'byName' => self::$byName,
			'byModule' => self::$byModule,
		];
	}

	/**
	 * A schema provider may describe a controller that is also discovered by the namespace scan.
	 * Its dto wins over the #[DtoType] attribute, while disabling is a veto on both sides.
	 */
	private function createDiscoveredControllerData(
		string $module,
		string $controllerFqcn,
		?string $namespace,
		?string $dtoFqcn,
		bool $isEnabled,
		?ControllerData $providerControllerData,
	): ControllerData
	{
		return new ControllerData(
			module: $module,
			controllerFqcn: $controllerFqcn,
			dtoFqcn: $providerControllerData?->dtoFqcn ?? $dtoFqcn,
			namespace: $namespace,
			enabled: $isEnabled && ($providerControllerData?->enabled ?? true),
		);
	}

	/**
	 * @param array<string, ControllerData> $controllersData
	 * @return array<string, true>
	 */
	private function collectDeclaredActionUris(array $controllersData): array
	{
		$actionUris = [];
		foreach ($controllersData as $controllerData)
		{
			foreach ($controllerData->getMethods() as $methodDescription)
			{
				$actionUris[$methodDescription->actionUri] = true;
			}
		}

		return $actionUris;
	}

	private function registerControllerData(string $controllerName, ControllerData $controllerData, array &$controllersCacheData): void
	{
		$controllerCacheData = $controllerData->toArray();

		CacheManager::set($this->getControllerCacheKey($controllerName), $controllerCacheData, CacheManager::ONE_HOUR_TTL);
		$controllersCacheData[$controllerName] = $controllerCacheData;

		self::$byName[$controllerName] = $controllerData;
		self::$byModule[$controllerData->module][$controllerName] = $controllerData;
	}

	private function getDtoClassFromAttributes(ReflectionClass $controllerReflection): ?string
	{
		$dtoTypeAttributes = $controllerReflection->getAttributes(DtoType::class);
		if (count($dtoTypeAttributes) > 1)
		{
			throw new TooManyAttributesException($controllerReflection->getName(), DtoType::class, 1);
		}

		foreach ($dtoTypeAttributes as $attribute)
		{
			/** @var DtoType $instance */
			$instance = $attribute->newInstance();
			if (!isset($this->reflections[$instance->type]))
			{
				$dtoReflection = new ReflectionClass($instance->type);
				if (!$dtoReflection->isSubclassOf(Dto::class))
				{
					return null;
				}
				$this->reflections[$instance->type] = $dtoReflection;
			}

			return $instance->type;
		}

		return null;
	}

	private function getScopesFromActionUri(string $actionUri): array
	{
		$scopeParts = explode('.', $actionUri);
		$scopeString = $scopeParts[0];
		$scopes = [$scopeString];
		$scopesCount = count($scopeParts);
		for ($i = 1; $i < $scopesCount; $i++)
		{
			if (!isset($scopeParts[$i]))
			{
				break;
			}
			$scopeString .= '.' . $scopeParts[$i];
			$scopes[] = $scopeString;
		}

		return $scopes;
	}

	private function resolveControllerEnabled(ReflectionClass $controllerReflection): bool
	{
		$enabledAttributes = $controllerReflection->getAttributes(Enabled::class);
		if ($enabledAttributes === [])
		{
			return true;
		}

		$enabledAttribute = $enabledAttributes[0]->newInstance();

		return $enabledAttribute->isEnabled();
	}

	private function getControllerCacheKey(string $controllerName): string
	{
		return self::CONTROLLERS_DATA_CACHE_KEY . '.' . $controllerName;
	}

	private function saveGeneratedDtosByModuleId(string $moduleId, array $generatedDtoCacheData): void
	{
		CacheManager::set(self::GENERATED_DTO_CACHE_KEY . '.' . $moduleId, $generatedDtoCacheData, CacheManager::ONE_HOUR_TTL);
	}

	private function getDtoByClass(?string $dtoClass): ?Dto
	{
		if ($dtoClass === null)
		{
			return null;
		}

		if (!isset($this->dtos[$dtoClass]))
		{
			try
			{
				$this->dtos[$dtoClass] = $dtoClass::create();
			}
			catch (\Exception)
			{
				return null;
			}
		}

		return $this->dtos[$dtoClass];
	}

	/**
	 * @param array<string, true> $declaredActionUris
	 */
	private function addDtoFieldMethods(Dto $dto, ControllerData $controllerData, array $declaredActionUris): void
	{
		$this->addDtoFieldMethodsForResourceUri($dto, $controllerData->module, $controllerData->getUri(), $declaredActionUris);
	}

	/**
	 * @param array<string, true> $declaredActionUris
	 */
	private function addDtoFieldMethodsForCustomController(ControllerData $controllerData, array $declaredActionUris): void
	{
		$dtoByResourceUri = [];
		foreach ($controllerData->getMethods() as $methodDescription)
		{
			$resourceUri = $this->getResourceUriByCrudMethod($methodDescription);
			if ($resourceUri === null || isset($dtoByResourceUri[$resourceUri]))
			{
				continue;
			}

			$dto = $this->getDtoByClass($methodDescription->dtoFqcn ?? $controllerData->dtoFqcn);
			if ($dto !== null)
			{
				$dtoByResourceUri[$resourceUri] = $dto;
			}
		}

		foreach ($dtoByResourceUri as $resourceUri => $dto)
		{
			$this->addDtoFieldMethodsForResourceUri($dto, $controllerData->module, $resourceUri, $declaredActionUris);
		}
	}

	private function getResourceUriByCrudMethod(MethodDescription $methodDescription): ?string
	{
		if (
			!in_array($methodDescription->method, ['add', 'delete', 'get', 'list', 'update'], true)
			|| str_contains($methodDescription->actionUri, '.field.')
		)
		{
			return null;
		}

		$methodSuffix = '.' . $methodDescription->method;
		if (!str_ends_with($methodDescription->actionUri, $methodSuffix))
		{
			return null;
		}

		$resourceUri = substr($methodDescription->actionUri, 0, -strlen($methodSuffix));

		return $resourceUri === '' ? null : $resourceUri;
	}

	/**
	 * @param array<string, true> $declaredActionUris
	 */
	private function addDtoFieldMethodsForResourceUri(Dto $dto, string $module, string $resourceUri, array $declaredActionUris): void
	{
		$listMethodUri = $resourceUri . '.field.list';

		if (!isset($declaredActionUris[$listMethodUri]))
		{
			$listMethodDescription = new MethodDescription(
				module: $module,
				controllerFqcn: Field::class,
				method: 'list',
				dtoFqcn: DtoFieldDto::class,
				scopes: $this->getScopesFromActionUri($listMethodUri),
				actionUri: $listMethodUri,
				title: null,
				description: null,
				queryParams: [
					'dtoClass' => $dto::class
				]
			);

			$this->systemControllersData[Field::class]->addMethod($listMethodDescription);
		}

		$getMethodUri = $resourceUri . '.field.get';

		if (!isset($declaredActionUris[$getMethodUri]))
		{
			$getMethodDescription = new MethodDescription(
				module: $module,
				controllerFqcn: Field::class,
				method: 'get',
				dtoFqcn: DtoFieldDto::class,
				scopes: $this->getScopesFromActionUri($getMethodUri),
				actionUri: $getMethodUri,
				title: null,
				description: null,
				queryParams: [
					'dtoClass' => $dto::class
				]
			);

			$this->systemControllersData[Field::class]->addMethod($getMethodDescription);
		}

		if ($dto->issetUserFields())
		{
			$entityId = $dto->getAttributeByName(OrmEntity::class)->getUserFieldId();
			$customMethods = ['list', 'get', 'delete', 'update', 'add'];
			foreach ($customMethods as $customMethod)
			{
				$fieldsMethod = in_array($customMethod, ['add', 'update'], true);
				$customMethodUri = $resourceUri . '.field.custom.' . $customMethod;
				if (!isset($declaredActionUris[$customMethodUri]))
				{
					$customMethodDescription = new MethodDescription(
						module: $module,
						controllerFqcn: Custom::class,
						method: $customMethod,
						dtoFqcn: CustomDto::class,
						scopes: $this->getScopesFromActionUri($customMethodUri),
						actionUri: $customMethodUri,
						title: null,
						description: null,
						queryParams: $fieldsMethod ? ['fields' => ['entityId' => $entityId]] : ['entityId' => $entityId],
					);
					$this->systemControllersData[Custom::class]->addMethod($customMethodDescription);
				}

				$enumMethodUri = $resourceUri . '.field.custom.enum.' . $customMethod;
				if (!isset($declaredActionUris[$enumMethodUri]))
				{
					$customEnumMethodDescription = new MethodDescription(
						module: $module,
						controllerFqcn: Custom\Enum::class,
						method: $customMethod,
						dtoFqcn: EnumDto::class,
						scopes: $this->getScopesFromActionUri($enumMethodUri),
						actionUri: $enumMethodUri,
						title: null,
						description: null,
						queryParams: $fieldsMethod ? ['fields' => ['entityId' => $entityId]] : ['entityId' => $entityId],
					);
					$this->systemControllersData[Custom\Enum::class]->addMethod($customEnumMethodDescription);
				}
			}
		}
	}

	public function getByName(string $name)
	{
		$controllerCacheData = CacheManager::get($this->getControllerCacheKey($name));
		if ($controllerCacheData === null)
		{
			
			$controllersData = $this->getData();
			$controllerData = $controllersData['byName'][$name] ?? null;
		}
		else
		{
			$controllerData = ControllerData::fromArray($controllerCacheData);
		}

		return $controllerData;
	}

	/**
	 * @param string $moduleId
	 * @return GeneratedDto[]
	 */
	public function getGeneratedDtosByModuleId(string $moduleId): array
	{
		$dtos = CacheManager::get(self::GENERATED_DTO_CACHE_KEY . '.' . $moduleId);

		return $dtos !== null ? $dtos : [];
	}

	/**
	 * Restores eval-generated DTO classes from cached descriptors for the given modules.
	 * Warm schema/documentation reads keep only FQCN; PHP classes must be rebuilt per request.
	 */
	public function ensureGeneratedDtoClassesLoaded(string ...$moduleIds): void
	{
		foreach (array_unique($moduleIds) as $moduleId)
		{
			if ($moduleId === '')
			{
				continue;
			}

			foreach ($this->getGeneratedDtosByModuleId($moduleId) as $generatedDto)
			{
				if (!$generatedDto instanceof GeneratedDto)
				{
					throw new \InvalidArgumentException('Generated DTO cache must contain GeneratedDto instances.');
				}

				Generator::generateByDto($generatedDto);
			}
		}
	}

	private function addMethodDescriptionsByControllerReflection(ControllerData $controllerData, ReflectionClass $controllerReflection): void
	{
		foreach ($controllerReflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method)
		{
			if (!str_ends_with($method->name, 'Action'))
			{
				continue;
			}

			$methodName = str_replace('Action', '', $method->name);
			$returnType = $method->getReturnType()?->getName();

			if (!TypeHelper::isSubclassOf($returnType, Response::class))
			{
				continue;
			}

			$actionUri = $controllerData->getMethodUri($methodName);
			$scopes = $this->getScopesFromActionUri($actionUri);

			$methodDescriptionData = [
				'module' => $controllerData->module,
				'method' => $methodName,
				'controllerFqcn' => $controllerData->controllerFqcn,
				'dtoFqcn' => $controllerData->dtoFqcn,
				'scopes' => $scopes,
				'actionUri' => $actionUri,
				'title' => null,
				'description' => null,
				'isEnabled' => true,
				'queryParams' => null,
				'isHidden' => false,
				'mcpTool' => null,
			];
			$isHidden = false;
			$isDeprecated = false;

			foreach ($method->getAttributes() as $attribute)
			{
				$attributeName = $attribute->getName();
				$attributeInstance = $attribute->newInstance();

				match ($attributeName)
				{
					Scope::class => $methodDescriptionData['scopes'][] = $attributeInstance->value,
					Title::class => $methodDescriptionData['title'] = $attributeInstance->value,
					Description::class => $methodDescriptionData['description'] = $attributeInstance->value,
					McpTool::class => $methodDescriptionData['mcpTool'] = McpToolMetadata::fromAttribute($attributeInstance),
					Hidden::class => $isHidden = $methodDescriptionData['isHidden'] = true,
					Deprecated::class => $isDeprecated = true,
					Enabled::class => call_user_func(function () use ($attributeInstance, &$methodDescriptionData) {
						$provider = new $attributeInstance->provider();
						$methodDescriptionData['isEnabled'] = $provider->isEnabled();
					}),
					default => null,
				};
			}

			if ($isHidden || $isDeprecated || !$methodDescriptionData['isEnabled'])
			{
				$methodDescriptionData['mcpTool'] = null;
			}

			$methodDescription = new MethodDescription(
				module: $methodDescriptionData['module'],
				controllerFqcn: $methodDescriptionData['controllerFqcn'],
				method: $methodDescriptionData['method'],
				dtoFqcn: $methodDescriptionData['dtoFqcn'],
				scopes: array_unique($methodDescriptionData['scopes']),
				actionUri: $methodDescriptionData['actionUri'],
				title: $methodDescriptionData['title'],
				description: $methodDescriptionData['description'],
				isEnabled: $methodDescriptionData['isEnabled'],
				queryParams: $methodDescriptionData['queryParams'],
				isHidden: $methodDescriptionData['isHidden'],
				mcpTool: $methodDescriptionData['mcpTool'],
				isDeprecated: $isDeprecated,
			);

			$controllerData->addMethod($methodDescription);
		}
	}
}