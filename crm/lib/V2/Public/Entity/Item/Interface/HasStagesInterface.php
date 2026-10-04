<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Interface;

use Bitrix\Crm\V2\Public\Entity\Item\Stage;
use Bitrix\Crm\V2\Public\Entity\Item\StageSemantic;
use Bitrix\Crm\V2\Public\Entity\User\Employee;
use Bitrix\Main\Type\DateTime;

interface HasStagesInterface
{
	public function getStageId(): ?string;

	public function setStageId(?string $stageId): static;

	public function getStage(): ?Stage;

	public function getStageSemantic(): ?StageSemantic;

	public function getMovedTime(): ?DateTime;

	public function getMovedById(): ?int;

	public function getMovedBy(): ?Employee;
}
