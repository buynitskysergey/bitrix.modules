<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\DataView;

enum DataViewStatus: string
{

	case NotMaterialized = 'N';

	case Actual = 'A';

	case SourceUnavailable = 'B';

	public static function getDefault(): self
	{
		return self::NotMaterialized;
	}

	public static function fromString(?string $value): self
	{
		return $value === null ? self::getDefault() : (self::tryFrom($value) ?? self::getDefault());
	}

	public function isActual(): bool
	{
		return $this === self::Actual;
	}

	public function isBroken(): bool
	{
		return $this === self::SourceUnavailable;
	}
}
