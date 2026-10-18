<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Mention;

use Bitrix\Note\Internal\Mention\MentionType;
use Bitrix\Note\Internal\Service\Mention\Resolver\CollectionMentionResolver;
use Bitrix\Note\Internal\Service\Mention\Resolver\DiskFolderMentionResolver;
use Bitrix\Note\Internal\Service\Mention\Resolver\DocumentMentionResolver;
use Bitrix\Note\Internal\Service\Mention\Resolver\TaskMentionResolver;
use Bitrix\Note\Internal\Service\Mention\Resolver\UserMentionResolver;

final class MentionResolver
{
	/** Optional injected resolver map: MentionType value => MentionEntityResolver */
	private ?array $injectedResolvers;

	/**
	 * @param array<string, MentionEntityResolver>|null $resolvers Optional map for DI / testing.
	 */
	public function __construct(?array $resolvers = null)
	{
		$this->injectedResolvers = $resolvers;
	}

	/**
	 * Resolves a mixed list of mention items to per-viewer metadata.
	 * Groups by type for a single batch resolve() call per type (no N+1).
	 *
	 * @param array<array{type: string, id: int}> $items
	 * @return ResolvedMention[]
	 */
	public function resolve(array $items): array
	{
		// Group ids by valid MentionType case; also keep case instances to avoid re-parsing in batch loop.
		$groups = [];
		$cases = [];
		foreach ($items as $item)
		{
			$case = MentionType::tryFrom((string)($item['type'] ?? ''));
			if ($case === null)
			{
				continue;
			}
			$groups[$case->value][] = (int)$item['id'];
			$cases[$case->value] = $case;
		}

		// One batch call per type; reuse already-parsed case to avoid redundant MentionType::from().
		$resolved = [];
		foreach ($groups as $typeValue => $ids)
		{
			$resolved[$typeValue] = $this->resolverFor($cases[$typeValue])->resolve(array_unique($ids));
		}

		// Reassemble in input order.
		$output = [];
		foreach ($items as $item)
		{
			$typeValue = (string)($item['type'] ?? '');
			$id = (int)($item['id'] ?? 0);
			$case = MentionType::tryFrom($typeValue);

			if ($case === null)
			{
				$output[] = ResolvedMention::unavailable($typeValue, $id, 'invalid');
				continue;
			}

			$output[] = $resolved[$case->value][$id] ?? ResolvedMention::unavailable($typeValue, $id, 'deleted');
		}

		return $output;
	}

	private function resolverFor(MentionType $case): MentionEntityResolver
	{
		// If an injected map is set and contains this type, use it (DI / testing).
		if ($this->injectedResolvers !== null && isset($this->injectedResolvers[$case->value]))
		{
			return $this->injectedResolvers[$case->value];
		}

		return match ($case) {
			MentionType::User => new UserMentionResolver(),
			MentionType::Document => new DocumentMentionResolver(),
			MentionType::Collection => new CollectionMentionResolver(),
			MentionType::Task => new TaskMentionResolver(),
			MentionType::DiskFolder => new DiskFolderMentionResolver(),
		};
	}
}
