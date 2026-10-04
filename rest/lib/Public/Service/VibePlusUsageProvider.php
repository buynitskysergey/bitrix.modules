<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Service;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Rest\Enum\APAuth\PasswordType;
use Bitrix\Rest\Enum\Integration\ElementCodeType;
use Bitrix\Rest\Internal\Entity\Application\AppAttribute;
use Bitrix\Rest\Internal\Entity\Application\AppAttributeCode;
use Bitrix\Rest\Internal\Entity\Application\AppOrigin;
use Bitrix\Rest\Internal\Model\AppAttributeTable;
use Bitrix\Rest\Preset\IntegrationTable;
use Bitrix\Rest\Internal\Repository\Application\AppFilter;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Marketplace\Immune;
use Bitrix\Rest\Public\Provider\IncomingWebhookProvider;
use Bitrix\Rest\Public\Provider\Params\IncomingWebhookFilter;
use Bitrix\Rest\Public\ValueObject\VibePlusUsageSnapshot;

/**
 * Provides raw REST usage facts without applying tariff decisions.
 */
final class VibePlusUsageProvider
{
	private const VIBECODE_ATTRIBUTE = 'vibecodeconnector';
	private const DEVELOPER_KEY_ATTRIBUTE = 'developer_key';
	private const ENABLED_ATTRIBUTE_VALUE = 'Y';
	private const MARKET_APPLICATION_BATCH_SIZE = 500;
	private const MARKET_APPLICATION_COUNT_CACHE_TTL = 86400;

	private readonly IncomingWebhookProvider $incomingWebhookProvider;
	private readonly \Closure $userIntegrationCountProvider;
	private readonly \Closure $hasUserIntegrationProvider;
	private readonly AppRepository $appRepository;
	private readonly \Closure $immuneAppListProvider;

	/**
	 * @param null|\Closure(): int $userIntegrationCountProvider
	 * @param null|\Closure(): string[] $immuneAppListProvider
	 * @param null|\Closure(): bool $hasUserIntegrationProvider
	 */
	public function __construct(
		?IncomingWebhookProvider $incomingWebhookProvider = null,
		?\Closure $userIntegrationCountProvider = null,
		?AppRepository $appRepository = null,
		?\Closure $immuneAppListProvider = null,
		?\Closure $hasUserIntegrationProvider = null,
	)
	{
		$this->incomingWebhookProvider = $incomingWebhookProvider ?? new IncomingWebhookProvider();
		$this->userIntegrationCountProvider = $userIntegrationCountProvider
			?? static fn(): int => self::queryUserIntegrationCount();
		$this->hasUserIntegrationProvider = $hasUserIntegrationProvider
			?? static fn(): bool => self::queryHasUserIntegration();
		$this->appRepository = $appRepository ?? new AppRepository();
		$this->immuneAppListProvider = $immuneAppListProvider ?? static fn(): array => Immune::getList();
	}

	public function hasUserRestUsage(): bool
	{
		return $this->incomingWebhookProvider->hasAny(
			new IncomingWebhookFilter(
				excludeAttributes: [self::VIBECODE_ATTRIBUTE => self::ENABLED_ATTRIBUTE_VALUE],
				type: PasswordType::User,
				active: true,
			),
		) || ($this->hasUserIntegrationProvider)();
	}

	public function getMarketApplicationCount(): int
	{
		return $this->appRepository->getCount(
			$this->buildMarketApplicationFilter(),
			self::MARKET_APPLICATION_COUNT_CACHE_TTL,
		);
	}

	/**
	 * @return string[]
	 */
	public function getMarketApplicationCodes(): array
	{
		$codes = [];
		$afterId = 0;
		$filter = $this->buildMarketApplicationFilter();

		do
		{
			$rows = $this->appRepository->getCodePage(
				$filter,
				$afterId,
				self::MARKET_APPLICATION_BATCH_SIZE,
			);
			$batchSize = count($rows);

			foreach ($rows as $row)
			{
				$afterId = max($afterId, (int)$row['ID']);
				$code = $row['CODE'];
				if (is_string($code) && $code !== '')
				{
					$codes[] = $code;
				}
			}
		}
		while ($batchSize === self::MARKET_APPLICATION_BATCH_SIZE);

		return array_values(array_unique($codes));
	}

	public function hasVibecodeUsage(): bool
	{
		return $this->incomingWebhookProvider->hasAny(
			new IncomingWebhookFilter(
				attributes: [self::VIBECODE_ATTRIBUTE => self::ENABLED_ATTRIBUTE_VALUE],
				active: true,
			),
		) || $this->appRepository->exists(
			(new AppFilter())
				->installed()
				->attributes([
					self::VIBECODE_ATTRIBUTE => self::ENABLED_ATTRIBUTE_VALUE,
				]),
		);
	}

	/**
	 * Returns the current raw REST usage snapshot.
	 */
	public function getSnapshot(): VibePlusUsageSnapshot
	{
		return new VibePlusUsageSnapshot(
			userWebhookCount: $this->getUserWebhookCount(),
			userIntegrationCount: ($this->userIntegrationCountProvider)(),
			marketApplicationCount: $this->getMarketApplicationCount(),
			vibecodeWebhookCount: $this->getVibecodeWebhookCount(),
			vibecodeApplicationCount: $this->getVibecodeApplicationCount(),
			developerKeyCount: $this->getDeveloperKeyCount(),
		);
	}

	private function getUserWebhookCount(): int
	{
		return $this->incomingWebhookProvider->getCount(
			new IncomingWebhookFilter(
				excludeAttributes: [self::VIBECODE_ATTRIBUTE => self::ENABLED_ATTRIBUTE_VALUE],
				type: PasswordType::User,
				active: true,
			),
		);
	}

	private static function queryUserIntegrationCount(): int
	{
		return (int)self::buildUserIntegrationQuery()->queryCountTotal();
	}

	private static function queryHasUserIntegration(): bool
	{
		return self::buildUserIntegrationQuery()
			->setSelect(['ID'])
			->setLimit(1)
			->fetch() !== false
		;
	}

	private static function buildUserIntegrationQuery(): Query
	{
		return IntegrationTable::query()
			->where('ELEMENT_CODE', '!=', ElementCodeType::IN_WEBHOOK->value)
			->where(Query::filter()
				->logic('or')
				->where('PASSWORD.TYPE', PasswordType::User->value)
				->whereNull('PASSWORD_ID'),
			)
			->where(Query::filter()
				->logic('or')
				->where('ELEMENT_CODE', '!=', ElementCodeType::APPLICATION->value)
				->whereNull('APP_ID')
				->where(Query::filter()
					->whereNotIn('APP_ID', self::buildVibecodeApplicationIdsQuery())
					->whereNotIn('APP_ID', self::buildForceInstalledApplicationIdsQuery()),
				),
			)
		;
	}

	private static function buildVibecodeApplicationIdsQuery(): Query
	{
		return AppAttributeTable::query()
			->setSelect(['APP_ID'])
			->where('TYPE', AppAttribute::TYPE_EXTERNAL)
			->where('CODE', self::VIBECODE_ATTRIBUTE)
			->where('VALUE', self::ENABLED_ATTRIBUTE_VALUE)
		;
	}

	private static function buildForceInstalledApplicationIdsQuery(): Query
	{
		return AppAttributeTable::query()
			->setSelect(['APP_ID'])
			->where('TYPE', AppAttribute::TYPE_SYSTEM)
			->where('CODE', AppAttributeCode::ForceInstalled->value)
			->where('VALUE', self::ENABLED_ATTRIBUTE_VALUE)
		;
	}

	private function buildMarketApplicationFilter(): AppFilter
	{
		$filter = (new AppFilter())
			->installed()
			->origin(AppOrigin::Marketplace)
		;

		$immuneAppCodes = $this->getImmuneAppCodes();
		if ($immuneAppCodes !== [])
		{
			$filter->where(
				Query::filter()->whereNotIn('CODE', $immuneAppCodes),
			);
		}

		return $filter;
	}

	private function getVibecodeWebhookCount(): int
	{
		return $this->incomingWebhookProvider->getCount(
			new IncomingWebhookFilter(
				attributes: [self::VIBECODE_ATTRIBUTE => self::ENABLED_ATTRIBUTE_VALUE],
				active: true,
			),
		);
	}

	private function getVibecodeApplicationCount(): int
	{
		return $this->appRepository->getCount(
			(new AppFilter())
				->installed()
				->attributes([
					self::VIBECODE_ATTRIBUTE => self::ENABLED_ATTRIBUTE_VALUE,
				]),
		);
	}

	private function getDeveloperKeyCount(): int
	{
		return $this->incomingWebhookProvider->getCount(
			new IncomingWebhookFilter(
				attributes: [
					self::VIBECODE_ATTRIBUTE => self::ENABLED_ATTRIBUTE_VALUE,
					self::DEVELOPER_KEY_ATTRIBUTE => self::ENABLED_ATTRIBUTE_VALUE,
				],
				active: true,
			),
		);
	}

	/**
	 * @return string[]
	 */
	private function getImmuneAppCodes(): array
	{
		$codes = ($this->immuneAppListProvider)();
		$codes = array_filter(
			$codes,
			static fn(mixed $code): bool => is_string($code) && $code !== '',
		);

		return array_values(array_unique($codes));
	}
}
