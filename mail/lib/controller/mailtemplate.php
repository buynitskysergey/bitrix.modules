<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Intranet;
use Bitrix\Mail\Integration\Crm\CrmTemplateProvider;
use Bitrix\Mail\Internal\Entity\MailTemplate\PreparedTemplate;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateListItem;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateReference;
use Bitrix\Mail\Internal\Service\MailTemplate\TemplateCatalog;
use Bitrix\Mail\Internal\Service\MailTemplate\TemplateProvider;
use Bitrix\Mail\Internal\Service\MailTemplate\TemplateUsageHistory;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use InvalidArgumentException;

class MailTemplate extends Controller
{
	public const ERROR_INVALID_ARGUMENT = 'MAIL_TEMPLATE_INVALID_ARGUMENT';

	private const DEFAULT_SEARCH_LIMIT = 20;
	private const MAX_SEARCH_LIMIT = 50;

	/**
	 * The query is looked up in the title of a template, and a title holds 128 characters, so a longer query
	 * matches nothing and has no business reaching the database.
	 */
	private const MAX_SEARCH_QUERY_LENGTH = 128;

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();

		if ($this->isIntranetModuleIncluded())
		{
			$filters[] = new Intranet\ActionFilter\IntranetUser();
		}

		return $filters;
	}

	public function configureActions(): array
	{
		$get = [
			'-prefilters' => [ActionFilter\Csrf::class],
			'+prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_GET])],
		];
		$post = [
			'+prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])],
		];

		return [
			'quickList' => $get,
			'search' => $get,
			'prepare' => $post,
			'setRememberLast' => $post,
			'recordUsage' => $post,
		];
	}

	public function quickListAction(): array
	{
		$userId = $this->getUserId();
		$history = $this->createUsageHistory();
		$providers = $this->createProviders();
		$lastHistoryReference = $history->getReferences($userId)[0] ?? null;
		$catalog = new TemplateCatalog($providers, $history);
		$items = $catalog->quickList($userId);
		$rememberLast = $catalog->getRememberLast($userId);
		$autoApplyReference = $rememberLast
			? $this->resolveAutoApplyReference($providers, $lastHistoryReference, $userId)
			: null
		;

		return [
			'items' => array_map($this->serializeListItem(...), $items),
			'rememberLast' => $rememberLast,
			'autoApply' => $autoApplyReference !== null
				? $this->serializeReference($autoApplyReference)
				: null,
		];
	}

	public function searchAction(
		string $query = '',
		int $offset = 0,
		int $limit = self::DEFAULT_SEARCH_LIMIT,
	): ?array
	{
		if ($offset < 0 || mb_strlen(trim($query)) > self::MAX_SEARCH_QUERY_LENGTH)
		{
			return $this->invalidArgument();
		}

		$limit = max(1, min(self::MAX_SEARCH_LIMIT, $limit));
		$page = $this->createCatalog()->search($query, $this->getUserId(), $offset, $limit);

		return [
			'items' => array_map($this->serializeListItem(...), $page->getItems()),
			'nextOffset' => $page->getNextOffset(),
		];
	}

	public function prepareAction(array $reference): ?array
	{
		$reference = $this->createReference($reference);
		if ($reference === null)
		{
			return null;
		}

		$result = $this->createCatalog()->prepare($reference, $this->getUserId());
		if (!$this->transferResultErrors($result))
		{
			return null;
		}

		$template = $result->getData()[TemplateProvider::RESULT_TEMPLATE] ?? null;
		if (!$template instanceof PreparedTemplate)
		{
			$this->addError(new Error(
				'Template preparation failed.',
				TemplateProvider::ERROR_PREPARATION_FAILED,
			));

			return null;
		}

		return ['template' => $this->serializePreparedTemplate($template)];
	}

	/**
	 * The parameter is left without a type declaration on purpose: `bool` would let the binder hand a
	 * `"false"` of form data over to a cast that turns it into `true`, and `mixed` no binder accepts at all
	 * (`Bitrix\Main\Engine\AutoWire\TypeDeclarationChecker`). The action reads the flag by itself instead.
	 *
	 * @param mixed $enabled
	 */
	public function setRememberLastAction($enabled = null): ?array
	{
		$isEnabled = $this->readFlag($enabled);
		if ($isEnabled === null)
		{
			return $this->invalidArgument();
		}

		$this->createCatalog()->setRememberLast($this->getUserId(), $isEnabled);

		return ['enabled' => $isEnabled];
	}

	public function recordUsageAction(array $reference): ?array
	{
		$reference = $this->createReference($reference);
		if ($reference === null)
		{
			return null;
		}

		$result = $this->createCatalog()->recordUsage($reference, $this->getUserId());
		if (!$this->transferResultErrors($result))
		{
			return null;
		}

		return ['recorded' => true];
	}

	protected function createCatalog(): TemplateCatalog
	{
		return new TemplateCatalog(
			$this->createProviders(),
			$this->createUsageHistory(),
		);
	}

	/**
	 * @return array<string, TemplateProvider>
	 */
	protected function createProviders(): array
	{
		return [CrmTemplateProvider::SOURCE => new CrmTemplateProvider()];
	}

	protected function createUsageHistory(): TemplateUsageHistory
	{
		return new TemplateUsageHistory();
	}

	protected function isIntranetModuleIncluded(): bool
	{
		return Loader::includeModule('intranet');
	}

	private function getUserId(): int
	{
		return (int)$this->getCurrentUser()->getId();
	}

	private function createReference(array $data): ?TemplateReference
	{
		$source = $data['source'] ?? null;
		$id = $data['id'] ?? null;
		if (is_string($id) && ctype_digit($id))
		{
			$id = (int)$id;
		}

		if (!is_string($source) || trim($source) === '' || !is_int($id) || $id <= 0)
		{
			$this->invalidArgument();

			return null;
		}

		try
		{
			return new TemplateReference($source, $id);
		}
		catch (InvalidArgumentException)
		{
			$this->invalidArgument();

			return null;
		}
	}

	/**
	 * Form data carries a flag as a string, so `"false"` must not become `true` by a cast. Anything but a
	 * boolean spelling PHP knows is an invalid argument rather than a guess.
	 */
	private function readFlag(mixed $value): ?bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		$isSpelled = is_int($value) || (is_string($value) && trim($value) !== '');

		return $isSpelled ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
	}

	private function invalidArgument(): null
	{
		$this->addError(new Error('Invalid mail template argument.', self::ERROR_INVALID_ARGUMENT));

		return null;
	}

	private function transferResultErrors(Result $result): bool
	{
		if ($result->isSuccess())
		{
			return true;
		}

		$allowedCodes = [
			TemplateProvider::ERROR_TEMPLATE_NOT_AVAILABLE,
			TemplateProvider::ERROR_PROVIDER_UNAVAILABLE,
			TemplateProvider::ERROR_PREPARATION_FAILED,
		];
		foreach ($result->getErrors() as $error)
		{
			$code = in_array($error->getCode(), $allowedCodes, true)
				? (string)$error->getCode()
				: TemplateProvider::ERROR_PREPARATION_FAILED
			;
			$this->addError(new Error($this->errorMessage($code), $code));
		}

		return false;
	}

	/**
	 * @param array<string, TemplateProvider> $providers
	 */
	private function resolveAutoApplyReference(
		array $providers,
		?TemplateReference $reference,
		int $userId,
	): ?TemplateReference
	{
		if ($reference === null)
		{
			return null;
		}

		$provider = $providers[$reference->getSource()] ?? null;
		if (!$provider instanceof TemplateProvider)
		{
			return null;
		}

		try
		{
			if (!$provider->isAvailable($userId))
			{
				return null;
			}

			$items = $provider->getByIds([$reference], $userId);
		}
		catch (\Throwable)
		{
			return null;
		}

		$item = $items[0] ?? null;
		if (!$item instanceof TemplateListItem || !$item->canApply() || !$item->getReference()->equals($reference))
		{
			return null;
		}

		return $item->getReference();
	}

	private function errorMessage(string $code): string
	{
		return match ($code)
		{
			TemplateProvider::ERROR_TEMPLATE_NOT_AVAILABLE => 'Template is not available.',
			TemplateProvider::ERROR_PROVIDER_UNAVAILABLE => 'Template provider is unavailable.',
			default => 'Template preparation failed.',
		};
	}

	private function serializeReference(TemplateReference $reference): array
	{
		return [
			'source' => $reference->getSource(),
			'id' => $reference->getId(),
		];
	}

	/**
	 * The subject belongs to a template the user can apply. For the rest the lists show the reason of the
	 * refusal in its place, so the value would reach no one and the answer carries the field empty.
	 */
	private function serializeListItem(TemplateListItem $item): array
	{
		return [
			'reference' => $this->serializeReference($item->getReference()),
			'title' => $item->getTitle(),
			'subject' => $item->canApply() ? $item->getSubject() : '',
			'canApply' => $item->canApply(),
			'disabledReason' => $item->getDisabledReason(),
		];
	}

	private function serializePreparedTemplate(PreparedTemplate $template): array
	{
		return [
			'reference' => $this->serializeReference($template->getReference()),
			'subject' => $template->getSubject(),
			'bodyHtml' => $template->getBodyHtml(),
		];
	}
}
