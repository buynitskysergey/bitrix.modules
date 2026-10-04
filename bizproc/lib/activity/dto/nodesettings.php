<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

use Bitrix\Main\Type\Contract\Arrayable;

final class NodeSettings implements Arrayable, \JsonSerializable
{
	/** @var NodeSettingsParam[] */
	public readonly array $params;

	private const BUILT_IN_KEYS = [
		'width' => true,
		'height' => true,
		'ports' => true,
	];

	public function __construct(
		public readonly ?int $width = null,
		public readonly ?int $height = null,
		public readonly ?NodePorts $ports = null,
		NodeSettingsParam ...$params,
	)
	{
		$this->params = $params;
	}

	public static function fromArray(array $array): self
	{
		return new self(
			$array['width'] ?? null,
			$array['height'] ?? null,
			is_array($array['ports'] ?? null) ? NodePorts::fromArray($array['ports']) : null,
			...self::paramsFromArray($array),
		);
	}

	public function toArray(): array
	{
		$settings = [
			'width' => $this->width,
			'height' => $this->height,
			'ports' => $this->ports?->toArray() ?? [],
		];

		foreach ($this->params as $param)
		{
			if ($param instanceof NodeSettingsParam)
			{
				foreach ($param->toArray() as $key => $value)
				{
					if (!isset(self::BUILT_IN_KEYS[$key]))
					{
						$settings[$key] = $value;
					}
				}
			}
		}

		return $settings;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	/**
	 * @return NodeSettingsParam[]
	 */
	private static function paramsFromArray(array $array): array
	{
		$params = [];

		foreach ($array as $key => $value)
		{
			if (!is_string($key) || isset(self::BUILT_IN_KEYS[$key]))
			{
				continue;
			}

			$params[] = new NodeSettingsParam($key, $value);
		}

		return $params;
	}
}
