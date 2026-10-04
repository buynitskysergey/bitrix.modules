<?php

declare(strict_types=1);

namespace Bitrix\Mail\Dto;

use Bitrix\Main\Mail\Converter;

final class MobileSignatureContextDto
{
	/**
	 * @param array<int, array{
	 *     id: int,
	 *     text: string,
	 *     scope: string,
	 *     senderKey: string|null,
	 *     senderKeys: string[],
	 *     senderTexts: array<string, string>,
	 * }> $signatures
	 * @param array<int, array{
	 *     key: string,
	 *     email: string,
	 *     name: string,
	 *     availableSignatureIds: int[],
	 *     selectedSignatureId: int|null,
	 * }> $senders
	 */
	public function __construct(
		private readonly array $signatures,
		private readonly array $senders,
	)
	{
	}

	/**
	 * Builds a mobile-safe DTO from the resolver output.
	 *
	 * @param array{
	 *     signatures: array<int, array{id: int, signature: string, scope: string, senderKey: string|null}>,
	 *     senders: array<int, array{
	 *         key: string,
	 *         email: string,
	 *         name: string,
	 *         availableSignatureIds: int[],
	 *         selectedSignatureId: int|null,
	 *     }>,
	 * } $context
	 */
	public static function fromResolvedContext(array $context): self
	{
		$signatures = [];
		foreach ($context['signatures'] ?? [] as $signature)
		{
			$item = [
				'id' => (int)$signature['id'],
				'text' => Converter::htmlToText((string)$signature['signature']),
				'scope' => (string)$signature['scope'],
				'senderKey' => $signature['senderKey'] === null
					? null
					: (string)$signature['senderKey'],
				'senderKeys' => array_values(array_map(
					'strval',
					$signature['senderKeys'] ?? ($signature['senderKey'] === null ? [] : [$signature['senderKey']]),
				)),
			];
			if (array_key_exists('senderTexts', $signature))
			{
				$item['senderTexts'] = array_map(
					static fn(string $html): string => Converter::htmlToText($html),
					$signature['senderTexts'],
				);
			}

			$signatures[] = $item;
		}

		return new self($signatures, array_values($context['senders'] ?? []));
	}

	/**
	 * Returns the public wire representation.
	 *
	 * @return array{signatures: array, senders: array}
	 */
	public function toArray(): array
	{
		return [
			'signatures' => $this->signatures,
			'senders' => $this->senders,
		];
	}
}
