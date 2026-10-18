<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Landing;

use Bitrix\Landing\Internals\RightsTable;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Role;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Read-only adapter over landing ACL rows for knowledge-base sites.
 *
 * Returns raw landing access types per Bitrix access code; mapping to note ACL
 * levels is intentionally left to LandingSource.
 */
class RightsReader
{
	private const DEFAULT_ENTITY_ID = 0;

	/**
	 * @return array<string, string[]> accessCode => unique landing types (read/edit/sett/...)
	 */
	public function getSiteAccessTypes(int $siteId): array
	{
		if ($siteId <= 0 || !Loader::includeModule('landing'))
		{
			return [];
		}

		return LandingScope::runInKnowledgeScope(function () use ($siteId): array {
			$typesByTaskId = $this->buildTypesByTaskId();
			$result = [];

			$siteRows = $this->loadSiteRows($siteId);

			// Defaults of the portal are a fallback, not an addition: landing reads them for a site
			// that has no rows of its own (\Bitrix\Landing\Rights::getOperations), and merging both
			// would raise the level of a site configured more strictly.
			$this->appendRows(
				$result,
				$siteRows === [] ? $this->loadDefaultRows() : $siteRows,
				$typesByTaskId,
			);

			// An explicit setting of the portal rather than a default, so it holds either way.
			foreach ($this->loadKnowledgeAdminCodes() as $accessCode)
			{
				$this->addType($result, $accessCode, Rights::ACCESS_TYPES['sett']);
			}

			ksort($result);

			return array_map(static function (array $types): array {
				sort($types);

				return array_values(array_unique($types));
			}, $result);
		});
	}

	/**
	 * @return array<int, string>
	 */
	private function buildTypesByTaskId(): array
	{
		$result = [];
		foreach (Rights::getAccessTasks() as $taskId => $task)
		{
			$type = (string)($task['NAME'] ?? '');
			if ($type === '')
			{
				continue;
			}

			$result[(int)$taskId] = $type;
		}

		return $result;
	}

	/**
	 * @param array<string, string[]> $result
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, string> $typesByTaskId
	 */
	private function appendRows(array &$result, array $rows, array $typesByTaskId): void
	{
		foreach ($rows as $row)
		{
			$accessCode = trim((string)($row['ACCESS_CODE'] ?? ''));
			if ($accessCode === '')
			{
				continue;
			}

			$taskIds = $row['TASK_ID'] ?? [];
			foreach ((array)$taskIds as $taskId)
			{
				$type = $typesByTaskId[(int)$taskId] ?? null;
				if ($type === null)
				{
					continue;
				}

				$this->addType($result, $accessCode, $type);
			}
		}
	}

	/**
	 * @return array<int, array{ACCESS_CODE: string, TASK_ID: int}>
	 */
	private function loadSiteRows(int $siteId): array
	{
		return $this->fetchRows($this->rowsFilter($siteId));
	}

	/**
	 * @return array<int, array{ACCESS_CODE: string, TASK_ID: int}>
	 */
	private function loadDefaultRows(): array
	{
		return $this->fetchRows($this->rowsFilter(self::DEFAULT_ENTITY_ID));
	}

	/**
	 * The rights mode decides where the rows live: the extended mode keeps them in a per-entity
	 * matrix (ROLE_ID = 0), the role mode on the roles of the current scope. Roles of another
	 * scope answer for their own sites, so they are filtered out of both reads - the same split
	 * \Bitrix\Landing\Rights::getOperations() applies to its own read.
	 *
	 * @return array<string, mixed>
	 */
	private function rowsFilter(int $entityId): array
	{
		return [
			'ENTITY_ID' => $entityId,
			'=ENTITY_TYPE' => Rights::ENTITY_TYPE_SITE,
			'ROLE_ID' => $this->isExtendedMode() ? 0 : $this->expectedRoleIds(),
		];
	}

	protected function isExtendedMode(): bool
	{
		return Rights::isExtendedMode();
	}

	/**
	 * The roles of the knowledge scope - as far as landing can name them. \Bitrix\Landing\Role holds
	 * them in a process-wide cache with no key of the scope, so the first read of the process fixes
	 * the set for the rest of it: called earlier under another scope, this answers with the roles of
	 * that one. \Bitrix\Landing\Rights::getOperations() reads them through the same cache, so the
	 * conversion is no looser than the source it copies - but a conversion runs in the import agent,
	 * on a hit that has landing behind it, so the case is a reachable one and not a theoretical one.
	 * Filtering by the TYPE of the role instead takes a join and belongs to a task of its own.
	 *
	 * @return int[]
	 */
	protected function expectedRoleIds(): array
	{
		return Role::getExpectedRoleIds();
	}

	/**
	 * @param array<string, mixed> $filter
	 * @return array<int, array{ACCESS_CODE: string, TASK_ID: int}>
	 */
	protected function fetchRows(array $filter): array
	{
		$rows = RightsTable::getList([
			'select' => ['ACCESS_CODE', 'TASK_ID'],
			'filter' => $filter,
		])->fetchAll();

		return is_array($rows) ? $rows : [];
	}

	/**
	 * @return string[]
	 */
	protected function loadKnowledgeAdminCodes(): array
	{
		$raw = Option::get('landing', 'access_codes_knowledge_admin', '');
		if ($raw === '')
		{
			return [];
		}

		$decoded = unserialize($raw, ['allowed_classes' => false]);
		if (!is_array($decoded))
		{
			return [];
		}

		$result = [];
		foreach ($decoded as $codes)
		{
			foreach ((array)$codes as $code)
			{
				$normalized = trim((string)$code);
				if ($normalized === '')
				{
					continue;
				}

				$result[$normalized] = true;
			}
		}

		return array_keys($result);
	}

	/**
	 * @param array<string, string[]> $result
	 */
	private function addType(array &$result, string $accessCode, string $type): void
	{
		if (!isset($result[$accessCode]))
		{
			$result[$accessCode] = [];
		}

		$result[$accessCode][] = $type;
	}
}

