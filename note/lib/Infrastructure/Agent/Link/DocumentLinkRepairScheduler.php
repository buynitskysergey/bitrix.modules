<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Link;

/**
 * [P2.T6] Hands a failed rebuild to {@see DocumentLinkRepairAgent}.
 *
 * A thin seam on purpose: it keeps
 * {@see \Bitrix\Note\Internal\Service\Link\DocumentLinkIndexService} — which lives in Internal and
 * is unit-tested without a database — from calling an Infrastructure static, and gives that test a
 * place to observe that a failure was in fact handed on — which is why the class is not final.
 */
class DocumentLinkRepairScheduler
{
	public function schedule(int $documentId): void
	{
		DocumentLinkRepairAgent::schedule($documentId);
	}
}
