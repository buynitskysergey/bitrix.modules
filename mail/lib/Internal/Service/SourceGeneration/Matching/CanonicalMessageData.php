<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration\Matching;

/**
 * One versioned canonical view of a message (ALG-01).
 *
 * The same structure is produced from a stored local message and from a MIME
 * freshly fetched by another generation, so both sides can be compared without
 * replaying the save path.
 *
 * Two levels of strength live here:
 *  - the content payload, hashed into the indexed CONTENT fingerprint. It holds
 *    only what a stored message always keeps: subject, addresses, date and the
 *    number of attachments. The body stays out of it on purpose - a saved body
 *    may have been prepared as a long one or cut to the allowed field size, and
 *    a body inside the index key would turn such messages into false NEW;
 *  - the body and the attachment names, kept in memory only, used to confirm a
 *    candidate found by the index.
 *
 * The message size is deliberately absent: another physical source may report a
 * different RFC822.SIZE for the very same letter.
 */
final readonly class CanonicalMessageData
{
	/**
	 * @param string[] $from
	 * @param string[] $to
	 * @param string[] $cc
	 * @param string[] $attachmentNames
	 * @param int[] $countVariants Numbers of attachments the same letter would carry if the module
	 *        had classified its parts by its other rule. See {@see getContentPayloadVariants()}.
	 */
	public function __construct(
		public int $version,
		public string $messageId,
		public string $subject,
		public array $from,
		public array $to,
		public array $cc,
		public int $date,
		public int $attachmentCount,
		public array $attachmentNames,
		public string $body,
		public bool $hasBody,
		public array $countVariants = [],
	)
	{
	}

	public function hasMessageId(): bool
	{
		return $this->messageId !== '';
	}

	/**
	 * Whether the letter knows a name for every attachment it counts, which is what makes
	 * the composition of two letters comparable at all.
	 *
	 * A stored message knows the names of the attachment rows it really created: a save
	 * that left them to the lazy path knows none, and a part that arrived without a name of
	 * its own is stored under a generated one.
	 */
	public function hasNamedAttachments(): bool
	{
		return $this->attachmentCount > 0
			&& count($this->attachmentNames) === $this->attachmentCount
			&& !in_array('', $this->attachmentNames, true)
		;
	}

	/**
	 * The payload of the indexed CONTENT fingerprint.
	 */
	public function getContentPayload(): string
	{
		return $this->contentPayloadOf($this->attachmentCount);
	}

	/**
	 * Every content payload this letter can be found by.
	 *
	 * The number of attachments is part of the key, and the module answers it by two different
	 * rules: one asks whether a part declared itself an attachment, the other whether it carries a
	 * file name. A letter saved through one of them and fetched through the other would answer a
	 * different number - and its stored twin would never be found, so the letter would arrive as a
	 * new one and lose what the portal has bound to it. So a letter whose parts the two rules
	 * classify differently is looked up by both numbers. The confirmation of a candidate is not
	 * touched by this: it still has to agree on the envelope, the names it knows and the body.
	 *
	 * @return string[]
	 */
	public function getContentPayloadVariants(): array
	{
		$payloads = [$this->getContentPayload()];

		foreach ($this->countVariants as $count)
		{
			$count = (int)$count;

			if ($count >= 0 && $count !== $this->attachmentCount)
			{
				$payloads[] = $this->contentPayloadOf($count);
			}
		}

		return array_values(array_unique($payloads));
	}

	/**
	 * Whether the letter could legitimately answer that number of attachments: its own number, or the
	 * one the other classification rule of the module would give the very same parts.
	 */
	public function countsAs(int $attachmentCount): bool
	{
		return $attachmentCount === $this->attachmentCount
			|| in_array($attachmentCount, array_map('intval', $this->countVariants), true)
		;
	}

	private function contentPayloadOf(int $attachmentCount): string
	{
		return implode("\n", [
			$this->getPayloadPrefix() . '/content',
			'subject:' . $this->subject,
			'from:' . implode(',', $this->from),
			'to:' . implode(',', $this->to),
			'cc:' . implode(',', $this->cc),
			'date:' . $this->date,
			'attachments:' . $attachmentCount,
		]);
	}

	/**
	 * The payload of the indexed MESSAGE_ID fingerprint.
	 */
	public function getMessageIdPayload(): string
	{
		return $this->getPayloadPrefix() . '/msgid' . "\n" . $this->messageId;
	}

	private function getPayloadPrefix(): string
	{
		return 'mail-canonical/' . $this->version;
	}
}
