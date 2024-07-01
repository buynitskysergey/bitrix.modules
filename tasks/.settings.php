<?php

use Bitrix\Tasks\Integration\UI\EntitySelector;

return array(
	'controllers' => [
		'value' => [
			'namespaces' => [
				'\\Bitrix\\Tasks\\Rest\\Controllers' => 'api',
				'\\Bitrix\\Tasks\\Scrum\\Controllers' => 'scrum',
				'\\Bitrix\\Tasks\\Flow\\Controllers' => 'flow',
			],
			'defaultNamespace' => '\\Bitrix\\Tasks\\Rest\\Controllers',
			'restIntegration' => [
				'enabled'=>true
			],
		],
		'readonly' => true,
	],
	'ui.uploader' => [
		'value' => [
			'allowUseControllers' => true,
		],
		'readonly' => true,
	],
	'ui.entity-selector' => [
		'value' => [
			'entities' => [
				[
					'entityId' => 'task',
					'provider' => [
						'moduleId' => 'tasks',
						'className' => EntitySelector\TaskProvider::class,
					],
				],
				[
					'entityId' => 'task-tag',
					'provider' => [
						'moduleId' => 'tasks',
						'className' => EntitySelector\TaskTagProvider::class,
					],
				],
				[
					'entityId' => 'task-template',
					'provider' => [
						'moduleId' => 'tasks',
						'className' => EntitySelector\TaskTemplateProvider::class,
					],
				],
				[
					'entityId' => 'scrum-user',
					'provider' => [
						'moduleId' => 'tasks',
						'className' => EntitySelector\ScrumUserProvider::class,
					],
				],
				[
					'entityId' => 'sprint-selector',
					'provider' => [
						'moduleId' => 'tasks',
						'className' => EntitySelector\SprintSelectorProvider::class,
					],
				],
				[
					'entityId' => 'epic-selector',
					'provider' => [
						'moduleId' => 'tasks',
						'className' => EntitySelector\EpicSelectorProvider::class,
					],
				],
				[
					'entityId' => 'template-tag',
					'provider' => [
						'moduleId' => 'tasks',
						'className' => EntitySelector\TemplateTagProvider::class,
					],
				],
				[
					'entityId' => 'flow',
					'provider' => [
						'moduleId' => 'tasks',
						'className' => EntitySelector\FlowProvider::class,
					],
				],
			],
			'filters' => [
				[
					'id' => 'tasks.userDataFilter',
					'entityId' => 'user',
					'className' => '\\Bitrix\\Tasks\\Integration\\UI\\EntitySelector\\UserDataFilter',
				],
				[
					'id' => 'tasks.projectDataFilter',
					'entityId' => 'project',
					'className' => '\\Bitrix\\Tasks\\Integration\\UI\\EntitySelector\\ProjectDataFilter',
				],
			],
			'extensions' => ['tasks.entity-selector'],
		],
		'readonly' => true,
	],
	'services' => [
		'value' => [
			'tasks.flow.command.addCommandHandler' => [
				'className' => \Bitrix\Tasks\Flow\Control\Command\AddCommandHandler::class,
			],
			'tasks.flow.command.updateCommandHandler' => [
				'className' => \Bitrix\Tasks\Flow\Control\Command\UpdateCommandHandler::class,
			],
			'tasks.flow.command.deleteCommandHandler' => [
				'className' => \Bitrix\Tasks\Flow\Control\Command\DeleteCommandHandler::class,
			],
			'tasks.flow.socialnetwork.project.service' => [
				'className' => \Bitrix\Tasks\Flow\Integration\Socialnetwork\GroupService::class,
			],
			'tasks.flow.kanban.service' => [
				'className' => \Bitrix\Tasks\Flow\Kanban\KanbanService::class,
			]
		],
	],
);
