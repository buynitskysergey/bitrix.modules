<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

use Bitrix\Crm\Service\Timeline\Layout\Mixin;

class EditableDate extends Date
{
	use Mixin\Actionable;

	public const STYLE_TEXT = 'text';
	public const STYLE_PILL = 'pill';
	public const STYLE_PILL_INLINE_GROUP = 'pill-inline-group';

	public const BACKGROUND_COLOR_WARNING = 'warning';
	public const BACKGROUND_COLOR_DEFAULT = 'default';
	public const BACKGROUND_COLOR_NONE = 'none';

	private string $style = self::STYLE_TEXT;
	private ?string $backgroundColor = null;
	protected ?bool $readonly = null;
	private ?bool $canChangeDeadline = null;

	public function getRendererName(): string
	{
		return
			$this->getStyle() === self::STYLE_TEXT
				? 'EditableDate'
				: 'DatePill'
		;
	}

	public function getStyle(): string
	{
		return $this->style;
	}

	public function setStyle(string $style): self
	{
		$this->style = $style;

		return $this;
	}

	public function getBackgroundColor(): ?string
	{
		return $this->backgroundColor;
	}

	public function setBackgroundColor(?string $backgroundColor): self
	{
		$this->backgroundColor = $backgroundColor;

		return $this;
	}

	public function isReadonly(): ?bool
	{
		return $this->readonly;
	}

	public function setReadonly(bool $isReadonly): self
	{
		$this->readonly = $isReadonly;

		return $this;
	}

	public function canChangeDeadline(): ?bool
	{
		return $this->canChangeDeadline;
	}

	public function setCanChangeDeadline(bool $canChangeDeadline): self
	{
		$this->canChangeDeadline = $canChangeDeadline;

		return $this;
	}

	protected function getProperties(): array
	{
		return array_merge(
			parent::getProperties(),
			[
				'action' => $this->getAction(),
				'backgroundColor' => $this->getBackgroundColor(),
				'isReadonly' => $this->isReadonly(),
				'styleValue' => $this->getStyle(),
				'canChangeDeadline' => $this->canChangeDeadline() ?? true,
			]
		);
	}
}
