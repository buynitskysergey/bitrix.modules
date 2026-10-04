<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlQueryException;

/**
 * A site-scoped option is consulted before the global one, so a row of any site can silently take an
 * active pilot back to the old instance.
 *
 * Names are returned rather than counted: a single unrelated row is enough to refuse, and the operator
 * has to be told which row to remove.
 */
final class DefaultShadowingOptionInspector implements ShadowingOptionInspector
{
	private const TABLE = 'b_option_site';

	/**
	 * Every option of the disk module that decides where a board is served, what its document_id is and
	 * who signs its token. A site-scoped row of any of them makes the answer of a pilot operation a lie.
	 *
	 * The list is spelled out rather than matched by the "flipchart." prefix: most of the prefixed options
	 * only shape the editor page (languages, autosave, reload after inactivity), and refusing an emergency
	 * rollback over a row of flipchart.default_language would leave the operator no way out of the pilot.
	 * A name that could not be shown harmless belongs here all the same.
	 *
	 * Option::set() lowercases every name it writes, so the literals are matched as stored.
	 */
	private const WATCHED_NAMES = [
		// Routing: the pilot list itself and the address pair of each instance. A row over the list is why
		// this check stands in the ways out of the pilot too: Option::set() rewrites the global row while
		// Option::get() keeps reading the site one.
		'flipchart.new_service_group_ids',
		'flipchart.new_service.api_host',
		'flipchart.new_service.app_url',
		'flipchart.api_host',
		'flipchart.app_url',

		// Token: the secret, the leeway of the JWT machinery, the header the token travels in and the
		// callback url carried inside the signed payload.
		'flipchart.jwt_secret',
		'flipchart.jwt_ttl',
		'flipchart.client_token_header_lookup',
		'flipchart.webhook_url',

		// document_id: the salt goes into the external id, which an instance remembers forever.
		'flipchart.document_id_salt',

		// The url an instance downloads a board from, together with its download grant.
		'flipchart.force_http_for_document_url',

		// Configuration::isUsingDocumentProxy(): with Y the token is signed by the proxy of the old
		// instance whatever the profile is, which is why every pilot guard reads it.
		'boards_use_documentproxy',
	];

	/**
	 * The connection is a parameter so that the branch below can be tested: a portal that has the table
	 * cannot be asked what happens without it.
	 */
	public function __construct(private readonly ?Connection $connection = null)
	{
	}

	/**
	 * @return string[]
	 */
	public function findShadowingSiteOptions(): array
	{
		$connection = $this->connection ?? Application::getConnection();
		$helper = $connection->getSqlHelper();

		$watched = implode(
			', ',
			array_map(static fn (string $name): string => "'" . $helper->forSql($name) . "'", self::WATCHED_NAMES),
		);

		$sql = "
			SELECT DISTINCT NAME FROM " . self::TABLE . "
			WHERE MODULE_ID = 'disk' AND NAME IN ({$watched})
			ORDER BY NAME ASC
		";

		try
		{
			$rows = $connection->query($sql)->fetchAll();
		}
		catch (SqlQueryException $exception)
		{
			// A portal without b_option_site is a state the framework supports (Option::load() catches the
			// same exception around the same table), and with no table nothing shadows anything. Any other
			// failure of this query is not that: an empty list would answer "no shadowing rows" without
			// having looked, and every caller reads that answer as permission to write the options.
			if ($connection->isTableExists(self::TABLE))
			{
				throw $exception;
			}

			return [];
		}

		$names = [];
		foreach ($rows as $row)
		{
			$names[] = (string)$row['NAME'];
		}

		return $names;
	}
}
