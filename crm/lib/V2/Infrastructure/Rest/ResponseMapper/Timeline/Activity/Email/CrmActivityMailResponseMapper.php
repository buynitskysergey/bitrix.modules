<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Timeline\Activity\Email;

use Bitrix\Crm\Activity\Email\Read\EmailActivity;
use Bitrix\Crm\Activity\Email\Read\ThreadEntry;
use CCrmActivityDirection;

class CrmActivityMailResponseMapper
{
	private const MAX_BODY_LENGTH = 50_000;

	public function mapList(array $data): array
	{
		$items = [];
		foreach (($data['activities'] ?? []) as $activity)
		{
			$items[] = $this->mapSummary($activity);
		}

		return [
			'items' => $items,
			'returnedCount' => (int)($data['returnedCount'] ?? count($items)),
			'hasMore' => (bool)($data['hasMore'] ?? false),
			'nextOffset' => isset($data['nextOffset']) ? (int)$data['nextOffset'] : null,
			'limit' => (int)($data['limit'] ?? 0),
			'offset' => (int)($data['offset'] ?? 0),
		];
	}

	public function mapContent(array $data): array
	{
		/** @var EmailActivity $activity */
		$activity = $data['activity'];
		[$body, $truncated] = $this->preparePlainTextBody($activity->description);

		return $this->mapActivityVisible($activity) + [
			'body' => $body,
			'isBodyTruncated' => $truncated,
			'bindings' => $this->mapBindings($data['bindings'] ?? []),
		];
	}

	public function mapThread(array $data): array
	{
		$messages = [];
		foreach (($data['messages'] ?? []) as $message)
		{
			$messages[] = $this->mapThreadItem($message);
		}

		return [
			'messages' => $messages,
			'isTruncated' => (bool)($data['truncated'] ?? false),
		];
	}

	public function mapSendResult(array $data): array
	{
		return [
			'activityId' => (int)($data['activityId'] ?? 0),
			'parentActivityId' => isset($data['parentActivityId']) ? (int)$data['parentActivityId'] : null,
			'from' => (string)($data['from'] ?? ''),
			'to' => $this->normalizeStringList($data['to'] ?? []),
			'cc' => $this->normalizeStringList($data['cc'] ?? []),
			'bcc' => $this->normalizeStringList($data['bcc'] ?? []),
			'isSyncedToImap' => (bool)($data['isSyncedToImap'] ?? $data['syncedToImap'] ?? false),
			'warnings' => $this->normalizeStringList($data['warnings'] ?? []),
		];
	}

	private function mapSummary(EmailActivity $activity): array
	{
		return [
			'id' => $activity->id,
			'subject' => $activity->subject,
			'dateTime' => $activity->getTimelineTime()?->format(DATE_ATOM),
			'isIncoming' => $activity->direction === CCrmActivityDirection::Incoming,
			'from' => $activity->getFrom(),
			'to' => $activity->getTo(),
		];
	}

	private function mapThreadItem(ThreadEntry $entry): array
	{
		if ($entry->hidden || $entry->activity === null)
		{
			return [
				'id' => 0,
				'cc' => null,
				'bcc' => null,
				'isHidden' => true,
			];
		}

		return $this->mapActivityVisible($entry->activity) + [
			'isHidden' => false,
		];
	}

	private function mapActivityVisible(EmailActivity $activity): array
	{
		return $this->mapSummary($activity) + [
			'cc' => $activity->getCc(),
			'bcc' => $activity->getBcc(),
		];
	}

	private function normalizeStringList(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$result = [];
		foreach ($value as $item)
		{
			if (is_scalar($item))
			{
				$result[] = (string)$item;
			}
		}

		return $result;
	}

	private function mapBindings(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$result = [];
		foreach ($value as $binding)
		{
			if (!is_array($binding))
			{
				continue;
			}

			$result[] = [
				'entityTypeId' => (int)($binding['entityTypeId'] ?? 0),
				'entityId' => (int)($binding['entityId'] ?? 0),
			];
		}

		return $result;
	}

	private function preparePlainTextBody(string $raw): array
	{
		$body = preg_replace('#<\s*(br\s*/?|/p|/div|/li|/tr)\s*>#i', "\n", $raw);
		$body = strip_tags((string)$body);
		$body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$body = trim((string)preg_replace('/\n{3,}/', "\n\n", $body));

		$truncated = mb_strlen($body) > self::MAX_BODY_LENGTH;
		if ($truncated)
		{
			$body = mb_substr($body, 0, self::MAX_BODY_LENGTH);
		}

		return [$body, $truncated];
	}
}
