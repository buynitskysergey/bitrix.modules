<?php

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Starter\Enum\Face;
use Bitrix\Bizproc\Starter\Enum\ManualStartSurface;
use Bitrix\Main\ModuleManager;

final class Context
{
	public readonly string $moduleId;
	public readonly Face $face;
	public readonly ?ManualStartSurface $manualStartSurface;

	private bool $isManual = false;

	public function __construct(string $moduleId, Face $face, ?ManualStartSurface $manualStartSurface = null)
	{
		$this->moduleId = $moduleId;
		$this->face = $face;
		$this->manualStartSurface = $manualStartSurface;
	}

	public function setIsManual(): self
	{
		$this->isManual = true;
		return $this;
	}

	public function isManualOperation(): bool
	{
		return $this->isManual;
	}
}
