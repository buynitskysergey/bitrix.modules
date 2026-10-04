<?php
$mail_default_option = array(
	// IMAP source generations resolve only for a mailbox with a completed G1 backfill,
	// see Bitrix\Mail\Internal\Service\SourceGeneration\BackfillService::isSourceGenerationEnabled()
	'source_generations_enabled' => 'N',
	// The emergency brake of the rollout: stops new migrations and generation synchronization
	'source_generations_migration_stopped' => 'N',
);
