<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity;

/**
 * Frame overlay styling carried by a frame block (NodeType::Frame). Each field is an optional override;
 * a null field falls back to the shared default when serialised into the node.frame* section. The agent
 * contract (DTO-01) lets the agent set frameColorName/frameContent (plus title); frameTextAlign and
 * frameSeparatorPosition keep their defaults.
 */
final class FrameData
{
	public const DEFAULT_COLOR_NAME = 'grey';
	public const DEFAULT_TEXT_ALIGN = 'none';
	public const DEFAULT_SEPARATOR_POSITION = 100;
	public const DEFAULT_CONTENT = '';
	public const DEFAULT_CONTENT_FILES = [];

	public function __construct(
		public readonly ?string $colorName = null,
		public readonly ?string $textAlign = null,
		public readonly ?int $separatorPosition = null,
		public readonly ?string $content = null,
		public readonly ?array $contentFiles = null,
	)
	{
	}

	public static function createFromNode(array $node): self
	{
		return new self(
			isset($node['frameColorName']) && is_string($node['frameColorName']) ? $node['frameColorName'] : null,
			isset($node['frameTextAlign']) && is_string($node['frameTextAlign']) ? $node['frameTextAlign'] : null,
			isset($node['frameSeparatorPosition']) && is_int($node['frameSeparatorPosition']) ? $node['frameSeparatorPosition'] : null,
			isset($node['frameContent']) && is_string($node['frameContent']) ? $node['frameContent'] : null,
			isset($node['frameContentFiles']) && is_array($node['frameContentFiles']) ? $node['frameContentFiles'] : null,
		);
	}

	/**
	 * @return array{frameColorName: string, frameTextAlign: string, frameSeparatorPosition: int, frameContent: string, frameContentFiles: array}
	 */
	public function toNodeArray(): array
	{
		return [
			'frameColorName' => $this->colorName ?? self::DEFAULT_COLOR_NAME,
			'frameTextAlign' => $this->textAlign ?? self::DEFAULT_TEXT_ALIGN,
			'frameSeparatorPosition' => $this->separatorPosition ?? self::DEFAULT_SEPARATOR_POSITION,
			'frameContent' => $this->content ?? self::DEFAULT_CONTENT,
			'frameContentFiles' => $this->contentFiles ?? self::DEFAULT_CONTENT_FILES,
		];
	}
}
