<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region;

use Bitrix\Mobile\Internal\Onboarding\PushType;

class DayConfig
{
	private int $day;
	private ?PushType $type = null;
	private string $title = '';
	private string $text = '';

	public function __construct(int $day)
	{
		$this->day = $day;
	}

	public function getDay(): int
	{
		return $this->day;
	}

	public function setType(PushType $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function getType(): ?PushType
	{
		return $this->type;
	}

	public function setTitle(string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setText(string $text): self
	{
		$this->text = $text;

		return $this;
	}

	public function getText(): string
	{
		return $this->text;
	}

	public function toArray(): array
	{
		return [
			'type' => $this->type,
			'title' => $this->title,
			'text' => $this->text,
		];
	}
}
