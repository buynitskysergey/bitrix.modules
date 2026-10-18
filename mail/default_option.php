<?php
$mail_default_option = array(
	// The rollout switch of the IMAP source generations,
	// see Bitrix\Mail\Internal\Service\SourceGeneration\BackfillService::isFeatureEnabled()
	'source_generations_enabled' => 'N',
	// The emergency brake of the rollout: stops new migrations and generation synchronization
	'source_generations_migration_stopped' => 'N',
	'migration_manual_stage_control_enabled' => 'N',
	'migration_admin_progress_early_visibility' => 'N',
	'unlimited_mail_sync_period_enabled' => 'N',
);
