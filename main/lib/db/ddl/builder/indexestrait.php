<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Builder;

use Bitrix\Main\DB\Ddl\IndexColumn;

trait IndexesTrait
{
	protected array $primaryKeys = [];
	/** @var array<string, array{type: string, columns: IndexColumn[]}> */
	private array $addedIndexes = [];

	public function addPrimaryKey(string $columnName): static
	{
		return $this->addPrimaryKeys([$columnName]);
	}

	public function addPrimaryKeys(array $columnNames): static
	{
		$this->primaryKeys = $columnNames;

		return $this;
	}

	/**
	 * @param string $indexName
	 * @param array<string|IndexColumn> $columns
	 */
	public function addIndex(string $indexName, array $columns): static
	{
		$columns = IndexColumn::normalizeList($columns);
		$indexName = $this->prepareIndexName('index', $indexName, $columns);
		$this->addedIndexes[$indexName] = [
			'type' => 'index',
			'columns' => $columns,
		];

		return $this;
	}

	/**
	 * @param string $indexName
	 * @param array<string|IndexColumn> $columns
	 */
	public function addUniqueIndex(string $indexName, array $columns): static
	{
		$columns = IndexColumn::normalizeList($columns);
		$indexName = $this->prepareIndexName('unique', $indexName, $columns);
		$this->addedIndexes[$indexName] = [
			'type' => 'unique',
			'columns' => $columns,
		];

		return $this;
	}

	/**
	 * @param string $indexName
	 * @param array<string|IndexColumn> $columns
	 */
	public function addFulltextIndex(string $indexName, array $columns): static
	{
		$columns = IndexColumn::normalizeList($columns);
		$indexName = $this->prepareIndexName('fulltext', $indexName, $columns);
		$this->addedIndexes[$indexName] = [
			'type' => 'fulltext',
			'columns' => $columns,
		];

		return $this;
	}

	/**
	 * @param IndexColumn[] $columns
	 */
	protected function prepareIndexName(string $type, string $indexName, array $columns): string
	{
		return $indexName;
	}
}
