<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\Compose;

/**
 * Recipients the compose form starts with, derived from the message it answers.
 *
 * ALG-01 (NORMATIVE):
 *   input: message, scenario
 *   selfEmail = message.__email
 *
 *   if scenario == 'replyAll':
 *       to = merge(message.__to, message.__reply_to)
 *       to = filter(to, item.email != selfEmail)
 *       cc = message.__cc                      // intentionally not filtered
 *   else if scenario == 'reply':
 *       to = message.__is_outcome ? message.__to : message.__reply_to
 *       cc = []
 *   else:                                      // 'new' and 'forward'
 *       to = message.__rcpt                    // non-empty only for the ?email= entry
 *       cc = []
 *
 *   return {
 *       to:  dialogItems(to),
 *       cc:  dialogItems(cc),
 *       bcc: [],
 *   }
 *
 * The asymmetry is deliberate and carried over as it stands: "To" drops the address of the mailbox
 * itself, "Copy" keeps it. Fields are read as Helper\Message::prepare() left them and their items
 * are passed on untouched: the set of keys is part of the send contract.
 *
 * The merge of the algorithm names an address once: the entity selector preselects one item per
 * address anyway, so a repetition of the two headers is dropped here rather than looked up twice.
 *
 * The dialogItems() step of the algorithm belongs to ComposeFormDataProvider, which already owns
 * the single conversion into entity selector items; the resolver decides which addresses go where
 * and stays free of the selector.
 */
class ReplyRecipientResolver
{
	/**
	 * @param array $message Message prepared by Helper\Message::prepare().
	 * @param string $scenario One of the ComposeFormDataProvider::SCENARIO_* values.
	 * @return array{to: array, cc: array, bcc: array} Recipients as they come from the message.
	 */
	public function resolve(array $message, string $scenario): array
	{
		return [
			'to' => $this->resolveTo($message, $scenario),
			'cc' => $this->resolveCc($message, $scenario),
			'bcc' => [],
		];
	}

	private function resolveTo(array $message, string $scenario): array
	{
		if ($scenario === ComposeFormDataProvider::SCENARIO_REPLY_ALL)
		{
			return $this->excludeSelf(
				$this->excludeRepeatedAddresses(
					array_merge($this->getField($message, '__to'), $this->getField($message, '__reply_to')),
				),
				$message['__email'] ?? null,
			);
		}

		if ($scenario === ComposeFormDataProvider::SCENARIO_REPLY)
		{
			return empty($message['__is_outcome'])
				? $this->getField($message, '__reply_to')
				: $this->getField($message, '__to')
			;
		}

		return $this->getField($message, '__rcpt');
	}

	private function resolveCc(array $message, string $scenario): array
	{
		return $scenario === ComposeFormDataProvider::SCENARIO_REPLY_ALL
			? $this->getField($message, '__cc')
			: []
		;
	}

	/**
	 * The sender of a letter is usually named by both headers the merge reads, and the entity selector
	 * preselects such an address once anyway. Dropping the repetition here keeps the order of the
	 * remaining addresses and spares the address book the second lookup.
	 */
	private function excludeRepeatedAddresses(array $recipients): array
	{
		$seen = [];
		$unique = [];

		foreach ($recipients as $recipient)
		{
			$email = (string)($recipient['email'] ?? '');

			if ($email !== '' && isset($seen[$email]))
			{
				continue;
			}

			$seen[$email] = true;
			$unique[] = $recipient;
		}

		return $unique;
	}

	private function excludeSelf(array $recipients, ?string $selfEmail): array
	{
		return array_values(
			array_filter(
				$recipients,
				static fn(array $recipient): bool => ($recipient['email'] ?? null) !== $selfEmail,
			),
		);
	}

	private function getField(array $message, string $field): array
	{
		return array_values((array)($message[$field] ?? []));
	}
}
