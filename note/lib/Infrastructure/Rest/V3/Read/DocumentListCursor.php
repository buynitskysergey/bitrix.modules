<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Read;

use Bitrix\Main\Error;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\DocumentCursorDto;
use Bitrix\Note\Infrastructure\Rest\V3\Request\PaginationNumber;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * Where the document list stopped.
 *
 * The list is answered by three streams of visibility, one after another, because the OR of
 * visibility has no single order to page through. A keyset alone therefore does not name a page
 * start: the same identifier belongs to a different row in a different stream, so the stream is
 * part of the cursor. Only the stream of collections is ordered by two keys - the other two run by
 * identifier alone, and a collection carried in them would be a key nothing is compared against.
 */
final class DocumentListCursor
{
	public const STREAM_COLLECTIONS = 'collections';
	public const STREAM_GRANTS = 'grants';
	public const STREAM_ORPHANS = 'orphans';

	private const STREAMS = [self::STREAM_COLLECTIONS, self::STREAM_GRANTS, self::STREAM_ORPHANS];

	public function __construct(
		public readonly string $stream,
		public readonly ?int $collectionId,
		public readonly int $id,
	)
	{
	}

	/**
	 * @throws RequestValidationException
	 */
	public static function parse(?array $raw): ?self
	{
		$stream = self::readStream($raw['stream'] ?? null);

		$id = PaginationNumber::read($raw['id'] ?? null, 'afterCursor.id');
		if ($id <= 0)
		{
			// A cursor without a real row behind it is not a page start - the list is answered from
			// its own beginning instead.
			return null;
		}

		return new self($stream, self::readCollectionId($raw, $stream), $id);
	}

	public function toDto(): DocumentCursorDto
	{
		$dto = new DocumentCursorDto();
		$dto->stream = $this->stream;
		$dto->collectionId = $this->collectionId;
		$dto->id = $this->id;

		return $dto;
	}

	/**
	 * @throws RequestValidationException
	 */
	private static function readStream(mixed $stream): string
	{
		// A cursor that names no stream is the start of the first one: the client that sent it has
		// not left the stream of collections yet.
		if ($stream === null)
		{
			return self::STREAM_COLLECTIONS;
		}

		if (!in_array($stream, self::STREAMS, true))
		{
			throw new RequestValidationException([
				new Error('unknown stream: expected one of ' . implode(', ', self::STREAMS), 'stream'),
			]);
		}

		return $stream;
	}

	private static function readCollectionId(array $raw, string $stream): ?int
	{
		if ($stream !== self::STREAM_COLLECTIONS || !isset($raw['collectionId']))
		{
			return null;
		}

		$collectionId = PaginationNumber::read($raw['collectionId'], 'afterCursor.collectionId');

		return $collectionId > 0 ? $collectionId : null;
	}
}
