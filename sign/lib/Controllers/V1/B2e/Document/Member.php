<?php

namespace Bitrix\Sign\Controllers\V1\B2e\Document;

use Bitrix\Main;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Operation\B2e\MyDocuments\ProcessBulkAction;
use Bitrix\Sign\Operation\SyncMemberStatus;
use Bitrix\Sign\Result\Operation\MemberWebStatusResult;
use Bitrix\Sign\Service;
use Bitrix\Sign\Type\MyDocumentsGrid\BulkAction;

class Member extends Controller
{
	public function configureActions(): array
	{
		$actionsConfiguration = parent::configureActions();
		$actionsConfiguration['processBulk']['-prefilters'] = [
			Main\Engine\ActionFilter\ContentType::class,
			Main\Engine\ActionFilter\HttpMethod::class,
		];
		$actionsConfiguration['processBulk']['+prefilters'] = [
			...($actionsConfiguration['processBulk']['+prefilters'] ?? []),
			// sign.v2.api posts JSON, ui.stepprocessing posts FormData
			new Main\Engine\ActionFilter\ContentType([
				Main\Engine\ActionFilter\ContentType::JSON,
				'application/x-www-form-urlencoded',
				'multipart/form-data',
			]),
			new Main\Engine\ActionFilter\HttpMethod([
				Main\Engine\ActionFilter\HttpMethod::METHOD_POST,
			]),
		];

		return $actionsConfiguration;
	}

	public function processBulkAction(array $data): array
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			return $this->addBulkActionError('B2E is unavailable', 'B2E_UNAVAILABLE');
		}

		// unavailable bulk action is a property of the portal, not of a document: the whole request fails
		if (!Storage::instance()->isB2eBulkActionAvailable())
		{
			return $this->addBulkActionError('Bulk action is unavailable', 'BULK_ACTION_UNAVAILABLE');
		}

		$currentUserId = (int)$this->getCurrentUser()?->getId();
		if ($currentUserId < 1)
		{
			return $this->addBulkActionError('User not found', 'USER_NOT_FOUND');
		}

		$action = is_string($data['actionType'] ?? null)
			? BulkAction::tryFrom($data['actionType'])
			: null;
		if ($action === null)
		{
			return $this->addBulkActionError('Invalid action', 'INVALID_ACTION');
		}

		$processedItems = $this->parseInteger($data['processedItems'] ?? 0);
		$lastProcessedId = array_key_exists('lastProcessedId', $data)
			? $this->parseInteger($data['lastProcessedId'])
			: null;
		if ($processedItems === null || (array_key_exists('lastProcessedId', $data) && $lastProcessedId === null))
		{
			return $this->addBulkActionError('Invalid process cursor', 'INVALID_CURSOR');
		}

		$result = (new ProcessBulkAction(
			memberIds: is_array($data['memberIds'] ?? null) ? $data['memberIds'] : [],
			action: $action,
			currentUserId: $currentUserId,
			processedItems: $processedItems,
			lastProcessedId: $lastProcessedId,
			limitWarning: Loc::getMessage('SIGN_B2E_MY_DOCUMENTS_BULK_ACTION_LIMIT_WARNING') ?? '',
		))->launch();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	public function callStatusAction(int $memberId): array
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			return [];
		}

		$container = Service\Container::instance();
		$memberRepository = $container->getMemberRepository();
		$member = $memberRepository->getById($memberId);
		$currentUserId = CurrentUser::get()->getId();
		if ($currentUserId === null)
		{
			return [];
		}

		$document = $container->getDocumentRepository()->getById($member->documentId);
		if (!Service\Container::instance()->getMemberService()->isUserLinksWithMember($member, $document, $currentUserId))
		{
			return [];
		}

		$result = (new SyncMemberStatus($member, $document))->launch();
		if (!$result->isSuccess())
		{
			return [];
		}

		/** @var MemberWebStatusResult $result */
		$status = $result->status;

		return compact('status');
	}

	private function parseInteger(mixed $value): ?int
	{
		if (is_int($value))
		{
			return $value;
		}

		if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1)
		{
			return (int)$value;
		}

		return null;
	}

	private function addBulkActionError(string $message, string $code): array
	{
		$this->addError(new Main\Error($message, $code));

		return [];
	}
}
