<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer;

/**
 * Resolves wiki file/image markers emitted by WikiMarkupConverter (ALG-01):
 *   ![name](wiki-asset://{fileId})   and   [name](wiki-asset://{fileId})
 *
 * The attachmentId is the Bitrix file id (digits only). The base transformer
 * enriches the marker into the note asset syntax once the file is linked, and
 * DownloadAttachmentsStep passes the same id to WikiSource::downloadAttachment.
 */
class WikiMdTransformer extends ImportMdTransformer
{
	protected function getPattern(): string
	{
		return '/(?P<linkPart>!?\[[^\]]*\])\((?P<url>wiki-asset:\/\/(?P<attachmentId>\d+))\)(?!\{)/u';
	}

	/**
	 * Turns every marker the attachment chain could not resolve into something the editor can show.
	 *
	 * `wiki-asset://` names a file of this portal, not an address, so there is nothing to fall back
	 * to: an attachment that could not be copied leaves its label behind, and an unlabelled one
	 * disappears. Leaving the marker would leave the reader an empty picture - the editor displays
	 * nothing for a scheme it does not know.
	 */
	public function unwrapUnresolvedAttachments(string $markdown): string
	{
		if ($markdown === '')
		{
			return $markdown;
		}

		return preg_replace_callback(
			$this->getPattern(),
			static function (array $matches): string {
				$linkPart = (string)$matches['linkPart'];

				return trim(substr($linkPart, (int)strpos($linkPart, '[') + 1, -1));
			},
			$markdown,
		) ?? $markdown;
	}
}
