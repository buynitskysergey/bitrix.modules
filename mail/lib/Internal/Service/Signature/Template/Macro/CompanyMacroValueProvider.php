<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template\Macro;

use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateContext;

final class CompanyMacroValueProvider implements SignatureMacroValueProvider
{
	private CompanyMacroDataSource $dataSource;
	private array $snapshots = [];
	private array $loadedIds = [];

	public function __construct(CompanyMacroDataSource $dataSource)
	{
		$this->dataSource = $dataSource;
	}

	public function getValues(SignatureTemplateContext $context, array $ids = []): array
	{
		if ($ids !== [] && !$this->containsCompanyId($ids))
		{
			return [];
		}

		$ids = $ids === [] ? ['company.name', 'company.legalAddress', 'company.actualAddress'] : $ids;
		$snapshot = $this->getSnapshot($context->userId, $ids);

		$values = [
			'company.name' => $snapshot['name'] ?? '',
			'company.legalAddress' => $snapshot['legalAddress'] ?? '',
			'company.actualAddress' => $snapshot['actualAddress'] ?? '',
		];

		return array_intersect_key($values, array_flip($ids));
	}

	private function containsCompanyId(array $ids): bool
	{
		foreach ($ids as $id)
		{
			if (str_starts_with($id, 'company.'))
			{
				return true;
			}
		}

		return false;
	}

	private function getSnapshot(int $userId, array $ids): array
	{
		$missingIds = array_values(array_diff($ids, $this->loadedIds[$userId] ?? []));
		if ($missingIds !== [])
		{
			try
			{
				$loaded = $this->dataSource->load($userId, $missingIds) ?? [];
			}
			catch (\Throwable)
			{
				$loaded = [];
			}

			$this->snapshots[$userId] = array_replace($this->snapshots[$userId] ?? [], $loaded);
			$this->loadedIds[$userId] = array_values(array_unique([
				...($this->loadedIds[$userId] ?? []),
				...$missingIds,
			]));
		}

		return $this->snapshots[$userId] ?? [];
	}
}
