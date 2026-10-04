<?php


return [
	'rest' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\BizprocDesigner\\Infrastructure\\Rest\\Controller',
		],
	],
	'controllers' => [
		'value' => [
			'namespaces' => [
				'\\Bitrix\\BizprocDesigner\\Controller' => 'api',
				'\\Bitrix\\BizprocDesigner\\Infrastructure\\Controller' => 'v2',
			],
			'defaultNamespace' => '\\Bitrix\\BizprocDesigner\\Controller',
			'restIntegration' => [
				'enabled' => false,
			],
		],
		'readonly' => true,
	],
	'services' => [
		'value' => [
			'bizprocdesigner.pull.manager' => [
				'className' => \Bitrix\BizprocDesigner\Internal\Integration\Pull\BizprocDesignerPullManager::class,
			],
			'bizprocdesigner.activity.node.filter.availability' => [
				'className' => \Bitrix\BizprocDesigner\Internal\Service\Activity\NodeFilterAvailability::class,
			],
			'bizprocdesigner.ai.assistant.draft.service' => [
				'className' => \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftCreatorService::class,
			],
			'bizprocdesigner.ai.assistant.draft.converter.service' => [
				'className' => \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftConverterService::class,
			],
			'bizprocdesigner.ai.assistant.workflow.converter.service' => [
				'className' => \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantWorkflowTemplateConverterService::class,
			],
			'bizprocdesigner.ai.assistant.last.workflow.service' => [
				'className' => \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\LastWorkflowService::class,
			],
			'bizprocdesigner.ai.assistant.user.block.service' => [
				'className' => \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\UserBlockService::class,
			],
			'bizprocdesigner.ai.assistant.agent.workflow.resolver.service' => [
				'className' => \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentWorkflowResolverService::class,
			],
			'bizprocdesigner.ai.assistant.agent.draft.service' => [
				'className' => \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentDraftService::class,
			],
			'bizprocdesigner.default.logger' => [
				'className' => \Bitrix\BizprocDesigner\Internal\Service\AddMessage2LogLogger::class,
			],
		],
	],
	'aiassistant.marta' => [
		'value' => [
			'scenarios' => [
				//\Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Scenario\FirstWorkflowScenario::class,
			],
		],
		'readonly' => true,
	],
];
