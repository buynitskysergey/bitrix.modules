<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Entity\History;

use InvalidArgumentException;

final readonly class Source
{
	/**
	 * Creates a history source with an optional positive identifier.
	 *
	 * @param SourceType $type
	 * @param int|null $id
	 */
	public function __construct(
		public SourceType $type,
		public ?int $id = null,
	)
	{
		if ($id !== null && $id <= 0)
		{
			throw new InvalidArgumentException('Source identifier must be positive.');
		}
	}

	/**
	 * Serializes the source without a null identifier.
	 *
	 * @return array{type: string, id?: int}
	 */
	public function toArray(): array
	{
		$result = ['type' => $this->type->value];
		if ($this->id !== null)
		{
			$result['id'] = $this->id;
		}

		return $result;
	}
}
