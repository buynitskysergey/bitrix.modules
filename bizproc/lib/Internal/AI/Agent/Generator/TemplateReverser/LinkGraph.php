<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

/**
 * Index over the flat Links list produced by LinkBuilder.
 *
 * Links format: [["fromName:fromPort", "toName:toPort"], ...]
 *
 * Only links whose both endpoints are known nodes enter the index. A link pointing outside the
 * known set is dangling: it is dropped and reported via danglingLinks(), never routed around the
 * unknown endpoint - the reverse must not invent edges the original graph did not have.
 */
final class LinkGraph
{
	public const UNKNOWN_END_FROM = 'from';
	public const UNKNOWN_END_TO = 'to';
	public const UNKNOWN_END_BOTH = 'both';

	/** @var array<string, array<string, array<int, array{name: string, port: string}>>> */
	private array $outgoing = [];

	/** @var array<string, array<string, array<int, array{name: string, port: string}>>> */
	private array $incoming = [];

	/** @var list<array{from: string, to: string, unknownEnd: string}> */
	private array $dangling = [];

	/**
	 * @param list<array> $links raw Links from template.json
	 * @param array<string, true> $knownNodes names of nodes present in template Children
	 */
	public function __construct(array $links, array $knownNodes)
	{
		foreach ($links as $link)
		{
			if (!is_array($link) || !isset($link[0], $link[1]))
			{
				continue;
			}

			$from = (string)$link[0];
			$to = (string)$link[1];
			[$fromName, $fromPort] = self::split($from);
			[$toName, $toPort] = self::split($to);

			$unknownEnd = self::unknownEnd($fromName, $toName, $knownNodes);
			if ($unknownEnd !== null)
			{
				$this->dangling[] = ['from' => $from, 'to' => $to, 'unknownEnd' => $unknownEnd];
				continue;
			}

			$this->outgoing[$fromName][$fromPort][] = ['name' => $toName, 'port' => $toPort];
			$this->incoming[$toName][$toPort][] = ['name' => $fromName, 'port' => $fromPort];
		}

		$this->deduplicate();
	}

	/**
	 * Links dropped from the index because an endpoint is not a known node.
	 *
	 * Order follows the Links list, so the equivalence report and the CLI read the same
	 * sequence on every run.
	 *
	 * @return list<array{from: string, to: string, unknownEnd: string}>
	 *         from, to - raw "name:port" endpoints as they appear in Links;
	 *         unknownEnd - which endpoint is missing from the known nodes: self::UNKNOWN_END_FROM,
	 *         self::UNKNOWN_END_TO or self::UNKNOWN_END_BOTH
	 */
	public function danglingLinks(): array
	{
		return $this->dangling;
	}

	/**
	 * @param array<string, true> $known
	 * @return string|null null when both endpoints are known
	 */
	private static function unknownEnd(string $fromName, string $toName, array $known): ?string
	{
		$isFromKnown = isset($known[$fromName]);
		$isToKnown = isset($known[$toName]);

		if ($isFromKnown && $isToKnown)
		{
			return null;
		}
		if (!$isFromKnown && !$isToKnown)
		{
			return self::UNKNOWN_END_BOTH;
		}

		return $isFromKnown ? self::UNKNOWN_END_TO : self::UNKNOWN_END_FROM;
	}

	/** Collapses repeated occurrences of the same edge; does not create edges. */
	private function deduplicate(): void
	{
		$this->outgoing = self::dedupePortMaps($this->outgoing);
		$this->incoming = self::dedupePortMaps($this->incoming);
	}

	/**
	 * @param array<string, array<string, list<array{name: string, port: string}>>> $maps
	 * @return array<string, array<string, list<array{name: string, port: string}>>>
	 */
	private static function dedupePortMaps(array $maps): array
	{
		foreach ($maps as $name => $portMap)
		{
			foreach ($portMap as $port => $edges)
			{
				$seen = [];
				$unique = [];
				foreach ($edges as $e)
				{
					$key = $e['name'] . ':' . $e['port'];
					if (!isset($seen[$key]))
					{
						$seen[$key] = true;
						$unique[] = $e;
					}
				}
				$maps[$name][$port] = $unique;
			}
		}

		return $maps;
	}

	public function follow(string $name, string $port): ?string
	{
		$targets = $this->outgoing[$name][$port] ?? [];

		return $targets[0]['name'] ?? null;
	}

	/** @return list<array{name: string, port: string}> */
	public function allOutgoing(string $name, string $port): array
	{
		return $this->outgoing[$name][$port] ?? [];
	}

	public function inDegree(string $name, string $port): int
	{
		return count($this->incoming[$name][$port] ?? []);
	}

	/**
	 * @return list<string> outgoing port ids sorted numerically by their index
	 *                     (so "o2" comes before "o10" — lexicographic sort would break that)
	 */
	public function outgoingPorts(string $name): array
	{
		$ports = [];
		foreach ($this->outgoing[$name] ?? [] as $port => $targets)
		{
			if (!empty($targets))
			{
				$ports[] = $port;
			}
		}
		usort($ports, static function (string $a, string $b): int {
			return ((int)substr($a, 1)) <=> ((int)substr($b, 1));
		});

		return $ports;
	}

	/** @return array{0: string, 1: string} */
	private static function split(string $endpoint): array
	{
		$parts = explode(':', $endpoint, 2);

		return [$parts[0] ?? '', $parts[1] ?? ''];
	}
}
