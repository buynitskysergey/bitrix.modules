<?php

namespace Bitrix\Crm\Copilot\AiCallScriptSelection\Entity;

final class AiCallScriptSelectionItem
{
	private ?int $id = null;
	private int $activityId;
	private int $assessmentSettingId;
	private int $jobId;
	private int $confidence;
	private ?string $rationale = null;

	public static function createFromEntityFields(array $fields): self
	{
		$instance = new self();
		$instance->id = isset($fields['ID']) ? (int)$fields['ID'] : null;
		$instance->activityId = (int)$fields['ACTIVITY_ID'];
		$instance->assessmentSettingId = (int)$fields['ASSESSMENT_SETTING_ID'];
		$instance->jobId = (int)$fields['JOB_ID'];
		$instance->confidence = (int)$fields['CONFIDENCE'];
		$instance->rationale = $fields['RATIONALE'] ?? null;

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

	public function getAssessmentSettingId(): int
	{
		return $this->assessmentSettingId;
	}

	public function getJobId(): int
	{
		return $this->jobId;
	}

	public function getConfidence(): int
	{
		return $this->confidence;
	}

	public function getRationale(): ?string
	{
		return $this->rationale;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'activityId' => $this->activityId,
			'assessmentSettingId' => $this->assessmentSettingId,
			'jobId' => $this->jobId,
			'confidence' => $this->confidence,
			'rationale' => $this->rationale,
		];
	}
}
