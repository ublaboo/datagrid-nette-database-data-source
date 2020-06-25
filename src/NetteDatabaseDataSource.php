<?php

declare(strict_types=1);

/**
 * @copyright   Copyright (c) 2016 ublaboo <ublaboo@paveljanda.com>
 * @author      Pavel Janda <me@paveljanda.com>
 * @package     Ublaboo
 */

namespace Ublaboo\NetteDatabaseDataSource;

use Nette\Database\Context;
use Nette\Database\ResultSet;
use PHPSQLParser\PHPSQLParser;
use Ublaboo\DataGrid\DataSource\IDataSource;
use Ublaboo\DataGrid\Filter\FilterDate;
use Ublaboo\DataGrid\Filter\FilterDateRange;
use Ublaboo\DataGrid\Filter\FilterMultiSelect;
use Ublaboo\DataGrid\Filter\FilterRange;
use Ublaboo\DataGrid\Filter\FilterSelect;
use Ublaboo\DataGrid\Filter\FilterText;
use Ublaboo\DataGrid\Utils\DateTimeHelper;
use Ublaboo\DataGrid\Utils\Sorting;

class NetteDatabaseDataSource implements IDataSource
{

	/**
	 * @var Context
	 */
	protected $connection;

	/**
	 * @var array
	 */
	protected $data = [];

	/**
	 * Query parameters
	 * @var array
	 */
	protected $queryParameters;

	/**
	 * Own query
	 * @var string
	 */
	protected $sql;

	/**
	 * @var PHPSQLParser
	 */
	protected $sqlParser;

	/**
	 * @var QueryHelper
	 */
	protected $queryHelper;


	public function __construct(Context $connection, string $sql, array $params = [])
	{
		$this->connection = $connection;
		$this->sql = $sql;

		$this->queryParameters = $params;

		$this->queryHelper = new QueryHelper($this->sql);
	}


	/**
	 * Get current sql + query parameters
	 */
	public function getQuery(): array
	{
		$sql = preg_replace('/_\?\w{13}\?_/', '?', $this->sql);

		return [$sql, $this->queryParameters];
	}


	/**
	 * @param string $sql
	 */
	protected function addParams(string $sql): array
	{
		$params = $this->queryParameters;

		array_unshift($params, $sql);

		return $params;
	}


	/**
	 * Call Context::query() with current sql + params
	 */
	protected function query(string $sql): ResultSet
	{
		$sql = preg_replace('/_\?\w{13}\?_/', '?', $sql);

		if ($sql === null) {
			throw new \UnexpectedValueException;
		}

		return $this->connection->query(...$this->addParams($sql));
	}


	/**
	 * @param mixed $value
	 */
	protected function applyWhere(string $column, $value, string $operator = '='): void
	{
		$id = '_?' . uniqid() . '?_';

		$this->sql = $this->queryHelper->where($column, $id, $operator);

		/**
		 * Find occurances of placeholders ('?') before inserted placeholder
		 */
		[$before, ] = explode($id, $this->sql);

		if ($before === null) {
			throw new \UnexpectedValueException;
		}

		$occurances = substr_count($before, '?');

		/**
		 * Add $value to query parameters at proper place
		 */
		if ($occurances === 0) {
			array_unshift($this->queryParameters, $value);
		} else {
			array_splice($this->queryParameters, $occurances, 0, $value);
		}
	}


	/********************************************************************************
	 *                          IDataSource implementation                          *
	 ********************************************************************************/


	/**
	 * {@inheritDoc}
	 */
	public function filter(array $filters): void
	{
		foreach ($filters as $filter) {
			if ($filter->isValueSet()) {
				if ($filter->getConditionCallback() !== null) {
					$this->sql = call_user_func_array(
						$filter->getConditionCallback(),
						[$this->sql, $filter->getValue(), & $this->queryParameters]
					);
					$this->queryHelper->resetQuery($this->sql);
				} else {
					if ($filter instanceof FilterText) {
						$this->applyFilterText($filter);
					} elseif ($filter instanceof FilterMultiSelect) {
						$this->applyFilterMultiSelect($filter);
					} elseif ($filter instanceof FilterSelect) {
						$this->applyFilterSelect($filter);
					} elseif ($filter instanceof FilterDate) {
						$this->applyFilterDate($filter);
					} elseif ($filter instanceof FilterDateRange) {
						$this->applyFilterDateRange($filter);
					} elseif ($filter instanceof FilterRange) {
						$this->applyFilterRange($filter);
					}
				}
			}
		}
	}


	public function getCount(): int
	{
		$sql = $this->queryHelper->getCountSelect();
		$query = $this->query($sql)->fetch();
		
		return $query === null
		
			? 0
		
			: $query['count'];
	}


	/**
	 * {@inheritDoc}
	 */
	public function getData(): array
	{
		return $this->data ?: $this->query($this->sql)->fetchAll();
	}


	/**
	 * {@inheritDoc}
	 */
	public function filterOne(array $condition): IDataSource
	{
		foreach ($condition as $column => $value) {
			$this->applyWhere($column, $value);
		}

		return $this;
	}


	public function limit(int $offset, int $limit): IDataSource
	{
		$sql = $this->queryHelper->limit($limit, $offset);

		$this->data = $this->query($sql)->fetchAll();

		return $this;
	}


	public function sort(Sorting $sorting): IDataSource
	{
		if (is_callable($sorting->getSortCallback())) {
			call_user_func(
				$sorting->getSortCallback(),
				$this->sql,
				$sorting->getSort()
			);

			return $this;
		}

		$sort = $sorting->getSort();

		if ($sort !== []) {
			foreach ($sort as $column => $order) {
				$this->sql = $this->queryHelper->orderBy((string) $column, $order);
			}
		}

		return $this;
	}


	protected function applyFilterDate(FilterDate $filter): void
	{
		$conditions = $filter->getCondition();

		$date = \DateTime::createFromFormat(
			$filter->getPhpFormat(),
			$conditions[$filter->getColumn()]
		);

		if ($date === false) {
			throw new \UnexpectedValueException;
		}

		$this->applyWhere("DATE({$filter->getColumn()})", $date->format('Y-m-d'));
	}


	protected function applyFilterDateRange(FilterDateRange $filter): void
	{
		$conditions = $filter->getCondition();

		$valueFrom = $conditions[$filter->getColumn()]['from'];
		$valueTo   = $conditions[$filter->getColumn()]['to'];

		if ($valueFrom) {
			$dateFrom = DateTimeHelper::tryConvertToDateTime($valueFrom, [$filter->getPhpFormat()]);
			$dateFrom->setTime(0, 0, 0);

			$dateFrom->setTime(0, 0, 0);

			$this->applyWhere("DATE({$filter->getColumn()})", $dateFrom->format('Y-m-d'), '>=');
		}

		if ($valueTo) {
			$dateTo = DateTimeHelper::tryConvertToDateTime($valueTo, [$filter->getPhpFormat()]);
			$dateTo->setTime(23, 59, 59);

			$this->applyWhere("DATE({$filter->getColumn()})", $dateTo->format('Y-m-d'), '<=');
		}
	}


	protected function applyFilterRange(FilterRange $filter): void
	{
		$conditions = $filter->getCondition();

		$valueFrom = $conditions[$filter->getColumn()]['from'];
		$valueTo   = $conditions[$filter->getColumn()]['to'];

		if ($valueFrom !== null && $valueFrom !== '') {
			$this->applyWhere($filter->getColumn(), $valueFrom, '>=');
		}

		if ($valueTo !== null && $valueTo !== '') {
			$this->applyWhere($filter->getColumn(), $valueTo, '<=');
		}
	}


	protected function applyFilterText(FilterText $filter): void
	{
		$or = [];
		$args = [];
		$big_or = '(';
		$big_or_args = [];
		$condition = $filter->getCondition();
		$isSeachExact = $filter->isExactSearch();
		$operator = $isSeachExact
			? '='
			: 'LIKE';

		foreach ($condition as $column => $value) {

			$like = '(';
			$args = [];

			if ($filter->hasSplitWordsSearch() === false) {
				$like .= "$column $operator ? OR ";
				$args[] = $isSeachExact
					? $value
					:"%$value%";
			}else{
				$words = explode(' ', $value);

				foreach ($words as $word) {
					$like .= "$column $operator ? OR ";
					$args[] = $isSeachExact
						? $word
						: "%$word%";
				}
			}

			$like = substr($like, 0, strlen($like) - 4).')';

			$or[] = $like;
			$big_or .= "$like OR ";
			$big_or_args = array_merge($big_or_args, $args);
		}

		if (sizeof($or) > 1) {
			$or = substr($big_or, 0, strlen($big_or) - 4).')';

			$args = $big_or_args;
		} else {
			$or = reset($or);
		}

		if ($or === false) {
			throw new \LogicException;
		}

		$this->sql = $this->queryHelper->whereSql($or);

		foreach ($args as $arg) {
			$this->queryParameters[] = $arg;
		}
	}


	protected function applyFilterSelect(FilterSelect $filter): void
	{
		foreach ($filter->getCondition() as $column => $value) {
			$this->applyWhere($column, $value);
		}
	}


	protected function applyFilterMultiSelect(FilterMultiSelect $filter): void
	{
		$or = [];
		$args = [];
		$big_or = '';
		$big_or_args = [];
		$condition = $filter->getCondition();
		foreach ($condition as $column => $values) {
			$queryPart = '(';
			foreach ($values as $value) {
				$queryPart .= "$column = ? OR ";
				$args[] = $value;
			}

			$queryPart = substr($queryPart, 0, strlen($queryPart) - 4).')';
			$or[] = $queryPart;
			$big_or .= "$queryPart OR ";
			$big_or_args = array_merge($big_or_args, $args);
		}

		if (sizeof($or) > 1) {
			$or = substr($big_or, 0, strlen($big_or) - 4).')';
			$args = $big_or_args;
		} else {
			$or = reset($or);
		}

		if ($or === false) {
			throw new \LogicException;
		}

		$this->sql = $this->queryHelper->whereSql($or);

		foreach ($args as $arg) {
			$this->queryParameters[] = $arg;
		}
	}
}
