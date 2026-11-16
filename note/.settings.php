<?php

return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Note\\Controller',
			'namespaces' => [
				'\\Bitrix\\Note\\Infrastructure\\Controller' => 'infrastructure',
				// The public circuit of a visitor: its own base controller with its own set of
				// pre-filters, and no Authentication among them. Removing this line turns the whole
				// public circuit off.
				'\\Bitrix\\Note\\Infrastructure\\ExternalAccess\\Controller' => 'external',
			],
		],
		'readonly' => true,
	],
	'rest' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Note\\Infrastructure\\Rest\\V3\\Controller',
			'documentation' => [
				'methods' => [
					'note.collection.list' => \Bitrix\Note\Infrastructure\Rest\V3\Documentation\CollectionListMethodProvider::class,
					'note.document.get' => \Bitrix\Note\Infrastructure\Rest\V3\Documentation\DocumentGetMethodProvider::class,
					'note.document.list' => \Bitrix\Note\Infrastructure\Rest\V3\Documentation\DocumentListMethodProvider::class,
					'note.document.search.list' => \Bitrix\Note\Infrastructure\Rest\V3\Documentation\DocumentSearchListMethodProvider::class,
					'note.document.tree.list' => \Bitrix\Note\Infrastructure\Rest\V3\Documentation\DocumentTreeListMethodProvider::class,
					'note.file.list' => \Bitrix\Note\Infrastructure\Rest\V3\Documentation\FileListMethodProvider::class,
				],
				'schemas' => [
					// Key must equal DocumentTreeItemDto's public type name (TypeAliasRegistry):
					// first two namespace parts + short class name, lowercased.
					'bitrix.note.documenttreeitemdto' => \Bitrix\Note\Infrastructure\Rest\V3\Documentation\DocumentTreeNodeSchemaProvider::class,
				],
			],
		],
	],
	'accessRightsV2' => [
		'value' => [
			'access' => '\\Bitrix\\Note\\Internal\\Access\\Permission\\Permission',
		],
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
					'entityId' => 'note-collection',
					'provider' => [
						'moduleId' => 'note',
						'className' => '\\Bitrix\\Note\\Internal\\Integration\\UI\\EntitySelector\\CollectionProvider',
					],
				],
				[
					'entityId' => 'note-document',
					'provider' => [
						'moduleId' => 'note',
						'className' => '\\Bitrix\\Note\\Internal\\Integration\\UI\\EntitySelector\\DocumentProvider',
					],
				],
				[
					'entityId' => 'note-disk-folder',
					'provider' => [
						'moduleId' => 'note',
						'className' => '\\Bitrix\\Note\\Internal\\Integration\\UI\\EntitySelector\\DiskFolderProvider',
					],
				],
			],
		],
		'readonly' => true,
	],
];
