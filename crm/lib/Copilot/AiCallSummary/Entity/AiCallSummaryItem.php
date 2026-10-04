<?php

namespace Bitrix\Crm\Copilot\AiCallSummary\Entity;

final class AiCallSummaryItem
{
	private ?int $id = null;
	private int $activityId;
	private int $jobId;
	private ?string $theme = null;
	private ?string $product = null;
	private ?string $intent = null;

	public static function createFromEntityFields(array $fields): self
	{
		$instance = new self();
		$instance->id = isset($fields['ID']) ? (int)$fields['ID'] : null;
		$instance->activityId = (int)$fields['ACTIVITY_ID'];
		$instance->jobId = (int)$fields['JOB_ID'];
		$instance->theme = $fields['THEME'] ?? null;
		$instance->product = $fields['PRODUCT'] ?? null;
		$instance->intent = $fields['INTENT'] ?? null;

		return $instance;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getActivityId(): int
	{
		return $this->activityId;
	}

	public function getJobId(): int
	{
		return $this->jobId;
	}

	public function getTheme(): ?string
	{
		return $this->theme;
	}

	public function getProduct(): ?string
	{
		return $this->product;
	}

	public function getIntent(): ?string
	{
		return $this->intent;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'activityId' => $this->activityId,
			'jobId' => $this->jobId,
			'theme' => $this->theme,
			'product' => $this->product,
			'intent' => $this->intent,
		];
	}
}
