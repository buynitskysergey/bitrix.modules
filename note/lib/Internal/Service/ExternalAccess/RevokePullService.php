<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Main\Loader;

/**
 * The live-update channel of a public link: its configuration for the page, and the revoke command.
 *
 * The tag is built from parts with the link record id in the lead, never from the token.
 * A token rotates every time the link is switched on again, so a tag built on it would orphan the
 * channel at the exact moment the revoke command has to arrive. Parts also leave room for the next
 * iteration to add a channel (a link plus a document, for editing) instead of rewriting the scheme.
 *
 * Nothing but the revoke command travels here: the channel is shared by every visitor of one link,
 * so neither content nor anything about people can be put in it.
 */
class RevokePullService
{
	public const COMMAND_REVOKE = 'externalAccessRevoked';

	private const TAG_PREFIX = 'note_external_link';
	private const TAG_SEPARATOR = '_';

	/**
	 * @return string Tag of the channel that carries the revoke command of this link.
	 */
	public function getChannelTag(int $linkRecordId): string
	{
		return $this->buildTag([(string)$linkRecordId]);
	}

	/**
	 * Channel configuration for the mount payload of the public page. The visitor has no account,
	 * so there is no personal channel to fall back on: without a channel handed over here the page
	 * would have no live updates at all.
	 *
	 * @return array|null null when there is nothing to subscribe to - the pull module is off, or it
	 *                    refused to issue a configuration.
	 */
	public function getPullConfig(int $linkRecordId): ?array
	{
		if ($linkRecordId <= 0 || !Loader::includeModule('pull'))
		{
			return null;
		}

		$channel = \Bitrix\Pull\Model\Channel::createWithTag($this->getChannelTag($linkRecordId));
		$config = \Bitrix\Pull\Config::get(['CHANNEL' => $channel, 'JSON' => true]);

		return is_array($config) && $config !== [] ? $config : null;
	}

	/**
	 * Tells every visitor of this link that the access is closed. Takes the record id and nothing
	 * else: the tag is built from that one number, so the call works after the record itself is
	 * gone - the cascade of the knowledge base deletion publishes past a committed transaction
	 * and would find nothing to read.
	 *
	 * `linkId` in the payload is a service field for logs: the addressing is the tag, and a foreign
	 * revoke cannot physically reach this channel.
	 */
	public function publishRevoke(int $linkRecordId): void
	{
		if ($linkRecordId <= 0 || !Loader::includeModule('pull'))
		{
			return;
		}

		$this->sendCommand($this->getChannelTag($linkRecordId), ['linkId' => $linkRecordId]);
	}

	/**
	 * @param array $params
	 */
	protected function sendCommand(string $channelTag, array $params): void
	{
		\Bitrix\Pull\Event::add(
			[\Bitrix\Pull\Model\Channel::createWithTag($channelTag)],
			[
				'module_id' => 'note',
				'command' => self::COMMAND_REVOKE,
				'params' => $params,
			],
		);
	}

	/**
	 * @param string[] $parts
	 */
	private function buildTag(array $parts): string
	{
		return implode(self::TAG_SEPARATOR, [self::TAG_PREFIX, ...$parts]);
	}
}
