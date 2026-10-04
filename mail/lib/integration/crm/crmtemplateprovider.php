<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Crm;

use Bitrix\Crm\V2\Public\Entity\MailTemplate\MailTemplate;
use Bitrix\Crm\V2\Public\Entity\MailTemplate\MailTemplatePage as CrmTemplatePage;
use Bitrix\Crm\V2\Public\Provider\MailTemplate\MailTemplateProvider as PublicCrmTemplateProvider;
use Bitrix\Mail\Internal\Entity\MailTemplate\PreparedTemplate;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateListItem;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplatePage;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateReference;
use Bitrix\Mail\Internal\Service\MailTemplate\TemplateProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Closure;
use Throwable;

final class CrmTemplateProvider implements TemplateProvider
{
	public const SOURCE = 'crm';
	public const DISABLED_REASON_CONTEXT_REQUIRED = 'CRM_CONTEXT_REQUIRED';

	private const MAX_OFFSET = 1000;

	private ?object $crmProvider = null;

	/** @var array<int, bool> */
	private array $crmAccessByUser = [];

	public function __construct(
		private readonly ?Closure $moduleLoader = null,
		private readonly ?Closure $providerFactory = null,
		private readonly ?Closure $contractAvailability = null,
		private readonly ?Closure $accessChecker = null,
	)
	{
	}

	/**
	 * The templates belong to CRM, so the user is let in on the terms its own section lets them in on:
	 * the module has to be there with the contract the templates come through, and CRM access has to be
	 * open to the user. Without the last question a user CRM is closed for would read shared templates
	 * through the mail form.
	 */
	public function isAvailable(int $userId): bool
	{
		$moduleAvailable = $this->moduleLoader !== null
			? (bool)($this->moduleLoader)()
			: Loader::includeModule('crm')
		;
		if (!$moduleAvailable)
		{
			return false;
		}

		$contractAvailable = $this->contractAvailability !== null
			? (bool)($this->contractAvailability)()
			: class_exists(PublicCrmTemplateProvider::class)
		;

		return $contractAvailable && $this->hasCrmAccess($userId);
	}

	public function search(
		string $query,
		int $userId,
		int $offset = 0,
		int $limit = 20,
	): TemplatePage
	{
		if (!$this->isAvailable($userId))
		{
			return new TemplatePage([], null, false);
		}

		$offset = max(0, $offset);
		if ($offset > self::MAX_OFFSET)
		{
			return new TemplatePage([], null, false);
		}

		/** @var CrmTemplatePage $page */
		$page = $this->getCrmProvider()->search($query, $userId, $offset, $limit);
		$items = array_map($this->mapTemplate(...), $page->getItems());
		$nextOffset = $page->hasMore() && !empty($items) ? $offset + count($items) : null;
		if ($nextOffset !== null && $nextOffset > self::MAX_OFFSET)
		{
			$nextOffset = null;
		}

		return new TemplatePage(
			items: $items,
			nextOffset: $nextOffset,
			hasMore: $nextOffset !== null,
		);
	}

	public function getByIds(array $references, int $userId): array
	{
		if (!$this->isAvailable($userId))
		{
			return [];
		}

		$templateIds = [];
		foreach ($references as $reference)
		{
			if (!$reference instanceof TemplateReference || $reference->getSource() !== self::SOURCE)
			{
				continue;
			}

			$templateIds[] = $reference->getId();
		}

		if (empty($templateIds))
		{
			return [];
		}

		return array_map(
			$this->mapTemplate(...),
			$this->getCrmProvider()->getByIds($templateIds, $userId),
		);
	}

	public function prepare(TemplateReference $reference, int $userId): Result
	{
		$result = new Result();
		if (!$this->isAvailable($userId))
		{
			return $result->addError(new Error(
				'Template provider is unavailable.',
				self::ERROR_PROVIDER_UNAVAILABLE,
			));
		}

		if ($reference->getSource() !== self::SOURCE)
		{
			return $result->addError($this->createNotAvailableError());
		}

		try
		{
			$templates = $this->getCrmProvider()->getByIds([$reference->getId()], $userId);
			$template = $templates[0] ?? null;
			if (!$template instanceof MailTemplate || !$template->isUniversal())
			{
				return $result->addError($this->createNotAvailableError());
			}

			$prepared = $this->getCrmProvider()->prepareUniversal($reference->getId(), $userId);
			if ($prepared === null)
			{
				return $result->addError(new Error(
					'Template preparation failed.',
					self::ERROR_PREPARATION_FAILED,
				));
			}

			return $result->setData([
				self::RESULT_TEMPLATE => new PreparedTemplate(
					reference: $reference,
					subject: $prepared->getSubject(),
					bodyHtml: $prepared->getBodyHtml(),
				),
			]);
		}
		catch (Throwable)
		{
			return $result->addError(new Error(
				'Template preparation failed.',
				self::ERROR_PREPARATION_FAILED,
			));
		}
	}

	/**
	 * Availability is asked again on every action, while the rights of a user hold for the whole
	 * request, so the answer is remembered by the user it was given for.
	 */
	private function hasCrmAccess(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->crmAccessByUser[$userId] ??= $this->accessChecker !== null
			? (bool)($this->accessChecker)($userId)
			: (new Permissions())->hasAccessToCrm($userId)
		;
	}

	private function getCrmProvider(): object
	{
		if ($this->crmProvider === null)
		{
			$this->crmProvider = $this->providerFactory !== null
				? ($this->providerFactory)()
				: new PublicCrmTemplateProvider()
			;
		}

		return $this->crmProvider;
	}

	private function mapTemplate(MailTemplate $template): TemplateListItem
	{
		$canApply = $template->isUniversal();

		return new TemplateListItem(
			reference: new TemplateReference(self::SOURCE, $template->getId()),
			title: $template->getTitle(),
			canApply: $canApply,
			disabledReason: $canApply ? null : self::DISABLED_REASON_CONTEXT_REQUIRED,
			subject: $template->getSubject(),
		);
	}

	private function createNotAvailableError(): Error
	{
		return new Error('Template is not available.', self::ERROR_TEMPLATE_NOT_AVAILABLE);
	}
}
