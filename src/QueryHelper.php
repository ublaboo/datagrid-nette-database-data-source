<?php

declare(strict_types=1);

namespace Ublaboo\NetteDatabaseDataSource;

use PHPSQLParser\PHPSQLCreator;
use PHPSQLParser\PHPSQLParser;

class QueryHelper
{

	/**
	 * @var PHPSQLParser
	 */
	protected $sqlParser;

	/**
	 * @var PHPSQLCreator
	 */
	private $sqlCreator;

	/**
	 * @var array
	 */
	protected $query;


	public function __construct(string $sql)
	{
		$this->sqlParser = new PHPSQLParser;
		$this->sqlCreator = new PHPSQLCreator;

		$this->query = $this->prepare($this->sqlParser->parse($sql));
	}


	public function resetQuery(string $sql): void
	{
		$this->query = $this->prepare($this->sqlParser->parse($sql));
	}


	/**
	 * In case query contains a more complicated query, place it within brackets: (<complicated_expr>)
	 */
	public function prepare(array $query): array
	{
		if (isset($query['WHERE']) && sizeof($query['WHERE']) > 1) {
			$where = $query['WHERE'];

			$query['WHERE'] = [[
				'expr_type' => 'bracket_expression',
				'base_expr' => '',
				'sub_tree' => $where,
			]];

			foreach ($where as $where_data) {
				$query['WHERE'][0]['base_expr'] .= ' ' . $where_data['base_expr'];
			}

			$query['WHERE'][0]['base_expr'] = '(' . trim($query['WHERE'][0]['base_expr']) . ')';
		}

		return $query;
	}


	public function getCountSelect(): string
	{
		$query = $this->query;

		$query['SELECT'] = [[
			'expr_type' => 'aggregate_function',
			'alias' => [
				'as'        => true,
				'name'      => 'count',
				'base_expr' => 'AS count',
				'no_quotes' => [
					'delim' => false,
					'parts' => ['count'],
				],
			],
			'base_expr' => 'COUNT',
			'sub_tree'  => [[
				'expr_type' => 'colref',
				'base_expr' => '*',
				'sub_tree'  => false,
			]],
		]];

		return $this->sqlCreator->create($query);
	}


	public function limit(int $limit, int $offset): string
	{
		$this->query['LIMIT'] = [
			'offset'   => $offset,
			'rowcount' => $limit,
		];

		return $this->sqlCreator->create($this->query);
	}


	public function orderBy(string $column, string $order): string
	{
		$this->query['ORDER'][] = [
			'expr_type' => 'colref',
			'base_expr' => $column,
			'no_quotes' => [
				'delim' => false,
				'parts' => [$column],
			],
			'subtree'   => false,
			'direction' => $order,
		];

		return $this->sqlCreator->create($this->query);
	}


	/**
	 * @param  mixed  $value
	 */
	public function where(string $column, $value, string $operator): string
	{
		if (!isset($this->query['WHERE'])) {
			$this->query['WHERE'] = [];
		} else {
			$this->query['WHERE'][] = [
				'expr_type' => 'operator',
				'base_expr' => 'AND',
				'sub_tree'  => false,
			];
		}

		/**
		 * Column
		 */
		if (strpos($column, '.') !== false) {
			/**
			 * Column prepanded with table/alias
			 */
			[$alias, $column] = explode('.', $column);

			$this->query['WHERE'][] = [
				'expr_type' => 'colref',
				'base_expr' => "{$alias}.{$column}",
				'no_quotes' => [
					'delim' => '.',
					'parts' => [$alias, $column],
				],
				'sub_tree'  => false,
			];
		} else {
			/**
			 * Simple column name
			 */
			$this->query['WHERE'][] = [
				'expr_type' => 'colref',
				'base_expr' => $column,
				'no_quotes' => [
					'delim' => false,
					'parts' => [$column],
				],
				'sub_tree'  => false,
			];
		}

		/**
		 * =
		 */
		$this->query['WHERE'][] = [
			'expr_type' => 'operator',
			'base_expr' => $operator,
			'sub_tree'  => false,
		];

		/**
		 * ?
		 * 	($value == '_?_')
		 */
		$this->query['WHERE'][] = [
			'expr_type' => 'const',
			'base_expr' => $value,
			'sub_tree'  => false,
		];

		return $this->sqlCreator->create($this->query);
	}


	public function whereSql(string $sql): string
	{
		if (!isset($this->query['WHERE'])) {
			$this->query['WHERE'] = [];
		} else {
			$this->query['WHERE'][] = [
				'expr_type' => 'operator',
				'base_expr' => 'AND',
				'sub_tree'  => false,
			];
		}

		$help_sql = 'SELECT * FROM TEMP WHERE' . $sql;
		$help_query = $this->sqlParser->parse($help_sql);

		$this->query['WHERE'][] = $help_query['WHERE'][0];

		return $this->sqlCreator->create($this->query);
	}

}
