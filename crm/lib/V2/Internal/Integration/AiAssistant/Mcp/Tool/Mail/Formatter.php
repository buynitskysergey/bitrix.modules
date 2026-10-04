<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail;

use Bitrix\Crm\Activity\Email\Read\EmailActivity;
use Bitrix\Crm\Activity\Email\Read\ThreadEntry;

final class Formatter
{
	public const MAX_BODY_LENGTH = 50_000;

	public function __construct(private readonly int $maxBodyLength = self::MAX_BODY_LENGTH)
	{
	}

	/**
	 * @return array{
	 *     id:int,
	 *     subject:string,
	 *     date:?string,
	 *     direction:int,
	 *     from:string,
	 *     to:list<string>
	 * }
	 */
	public function formatSummary(EmailActivity $activity): array
	{
		return [
			'id' => $activity->id,
			'subject' => $activity->subject,
			'date' => $activity->getTimelineTime()?->format('Y-m-d H:i:s'),
			'direction' => $activity->direction,
			'from' => $activity->getFrom(),
			'to' => $activity->getTo(),
		];
	}

	/**
	 * @return array{
	 *     id:int,
	 *     subject:string,
	 *     date:?string,
	 *     direction:int,
	 *     from:string,
	 *     to:list<string>,
	 *     cc:list<string>,
	 *     bcc:list<string>
	 * }
	 */
	public function formatVisible(EmailActivity $activity): array
	{
		return $this->formatSummary($activity) + [
			'cc' => $activity->getCc(),
			'bcc' => $activity->getBcc(),
		];
	}

	/**
	 * @return array{hidden:bool}
	 */
	public function formatHidden(): array
	{
		return ['hidden' => true];
	}

	/**
	 * @return array{
	 *     id:int,
	 *     subject:string,
	 *     date:?string,
	 *     direction:int,
	 *     from:string,
	 *     to:list<string>,
	 *     cc:list<string>,
	 *     bcc:list<string>,
	 *     body:string,
	 *     truncated:bool,
	 *     bindings:list<array{entityTypeId:int, entityId:int}>
	 * }
	 */
	public function formatContent(EmailActivity $activity, array $bindings): array
	{
		[$body, $truncated] = $this->preparePlainTextBody($activity->description);

		return $this->formatVisible($activity) + [
			'body' => $body,
			'truncated' => $truncated,
			'bindings' => $bindings,
		];
	}

	/**
	 * @return array{
	 *     id:int,
	 *     subject:string,
	 *     date:?string,
	 *     direction:int,
	 *     from:string,
	 *     to:list<string>,
	 *     cc:list<string>,
	 *     bcc:list<string>
	 * }|array{hidden:bool}
	 */
	public function formatThreadEntry(ThreadEntry $entry): array
	{
		if ($entry->hidden || $entry->activity === null)
		{
			return $this->formatHidden();
		}

		return $this->formatVisible($entry->activity);
	}

	/**
	 * @return array{0:string, 1:bool}
	 */
	public function preparePlainTextBody(string $raw): array
	{
		$body = preg_replace('#<\s*(br\s*/?|/p|/div|/li|/tr)\s*>#i', "\n", $raw);
		$body = strip_tags((string)$body);
		$body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$body = trim((string)preg_replace('/\n{3,}/', "\n\n", $body));

		$truncated = mb_strlen($body) > $this->maxBodyLength;
		if ($truncated)
		{
			$body = mb_substr($body, 0, $this->maxBodyLength);
		}

		return [$body, $truncated];
	}
}
