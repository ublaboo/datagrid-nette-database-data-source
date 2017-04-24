<?php

/**
 * @copyright   Copyright (c) 2016 ublaboo <ublaboo@paveljanda.com>
 * @author      Pavel Janda <me@paveljanda.com>
 * @package     Ublaboo
 */

namespace Ublaboo\NetteDatabaseDataSource;

use Nette\Database\Context;
use Ublaboo\DataGrid\Filter;
use Ublaboo\DataGrid\Utils\Sorting;
use Ublaboo\DataGrid\DataSource\IDataSource;
use Nette\Utils\Callback;
use Ublaboo\NetteDatabaseDataSource\Exception\NetteDatabaseDataSourceException;

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
	protected $query_parameters;

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


	/**
	 * @param Context $connection
	 * @param string $sql
	 * @param array $params
	 */
	public function __construct(Context $connection, $sql, array $params = [])
	{
		$this->connection = $connection;
		$this->sql = $sql;

		$this->query_parameters = $params;

		$this->queryHelper = new QueryHelper($this->sql);
	}


	/**
	 * Get current sql + query parameters
	 * @return array
	 */
	public function getQuery()
	{
		$sql = preg_replace('/_\?\w{13}\?_/', '?', $this->sql);

		return [$sql, $this->query_parameters];
	}


	/**
	 * @param string $sql
	 * @return array
	 */
	protected function addParams($sql)
	{
		$params = $this->query_parameters;

		array_unshift($params, $sql);

		return $params;
	}


	/**
	 * Call Context::query() with current sql + params
	 * @param  string $sql
	 * @return Nette\Database\ResultSet
	 */
	protected function query($sql)
	{
		$sql = preg_replace('/_\?\w{13}\?_/', '?', $sql);

		return call_user_func_array([$this->connection, 'query'], $this->addParams($sql));
	}


	/**
	 * @param  string $column
	 * @param  mixed $value
	 * @param  string $operator
	 * @return void
	 */
	protected function applyWhere($column, $value, $operator = '=')
	{
		$id = '_?' . uniqid() . '?_';

		$this->sql = $this->queryHelper->where($column, $id, $operator);

		/**
		 * Find occurances of placeholders ('?') before inserted placeholder
		 */
		list($before, $after) = explode($id, $this->sql);

		$occurances = substr_count($before, '?');

		/**
		 * Add $value to query parameters at proper place
		 */
		if ($occurances === 0) {
			array_unshift($this->query_parameters, $value);
		} else {
			array_splice($this->query_parameters, $occurances, 0, $value);
		}
	}


	/********************************************************************************
	 *                          IDataSource implementation                          *
	 ********************************************************************************/


	/**
	 * Filter data
	 * @param array $filters
	 * @return static
	 */
	public function filter(array $filters)
	{
		foreach ($filters as $filter) {
			if ($filter->isValueSet()) {
				if ($filter->hasConditionCallback()) {
					$this->sql = call_user_func_array(
						$filter->getConditionCallback(),
						[$this->sql, $filter->getValue(), & $this->query_parameters]
					);
					$this->queryHelper->resetQuery($this->sql);
				} else {
					if ($filter instanceof Filter\FilterText) {
						$this->applyFilterText($filter);
					} else if ($filter instanceof Filter\FilterMultiSelect) {
						$this->applyFilterMultiSelect($filter);
					} else if ($filter instanceof Filter\FilterSelect) {
						$this->applyFilterSelect($filter);
					} else if ($filter instanceof Filter\FilterDate) {
						$this->applyFilterDate($filter);
					} else if ($filter instanceof Filter\FilterDateRange) {
						$this->applyFilterDateRange($filter);
					} else if ($filter instanceof Filter\FilterRange) {
						$this->applyFilterRange($filter);
					}
				}
			}
		}

		return $this;
	}


	/**
	 * Get count of data
	 * @return int
	 */
	public function getCount()
	{
		$sql = $this->queryHelper->getCountSelect();
		$query = $this->query($sql);
		$rowsCount = $query->getRowCount();
		switch ($rowsCount){
			case 0;
			case null:
				return 0;
			case 1:
				return $query->fetch()->count;
			default:
				return $rowsCount;
		}
	}


	/**
	 * Get the data
	 * @return array
	 */
	public function getData()
	{
		return $this->data ?: $this->query($this->sql)->fetchAll();
	}


	/**
	 * Filter data - get one row
	 * @param array $condition
	 * @return static
	 */
	public function filterOne(array $condition)
	{
		foreach ($condition as $column => $value) {
			$this->applyWhere($column, $value);
		}

		return $this;
	}


	/**
	 * Filter by date
	 * @param  Filter\FilterDate $filter
	 * @return void
	 */
	public function applyFilterDate(Filter\FilterDate $filter)
	{
		$conditions = $filter->getCondition();

		$date = \DateTime::createFromFormat($filter->getPhpFormat(), $conditions[$filter->getColumn()]);

		$this->applyWhere("DATE({$filter->getColumn()})", $date->format('Y-m-d'));
	}


	/**
	 * Filter by date range
	 * @param  Filter\FilterDateRange $filter
	 * @return void
	 */
	public function applyFilterDateRange(Filter\FilterDateRange $filter)
	{
		$conditions = $filter->getCondition();

		$value_from = $conditions[$filter->getColumn()]['from'];
		$value_to   = $conditions[$filter->getColumn()]['to'];

		if ($value_from) {
			$date_from = \DateTime::createFromFormat($filter->getPhpFormat(), $value_from);
			$date_from->setTime(0, 0, 0);

			$this->applyWhere("DATE({$filter->getColumn()})", $date_from->format('Y-m-d'), '>=');
		}

		if ($value_to) {
			$date_to = \DateTime::createFromFormat($filter->getPhpFormat(), $value_to);
			$date_to->setTime(23, 59, 59);

			$this->applyWhere("DATE({$filter->getColumn()})", $date_to->format('Y-m-d'), '<=');
		}
	}


	/**
	 * Filter by range
	 * @param  Filter\FilterRange $filter
	 * @return void
	 */
	public function applyFilterRange(Filter\FilterRange $filter)
	{
		$conditions = $filter->getCondition();

		$value_from = $conditions[$filter->getColumn()]['from'];
		$value_to   = $conditions[$filter->getColumn()]['to'];

		if ($value_from) {
			$this->applyWhere($filter->getColumn(), $value_from, '>=');
		}

		if ($value_to) {
			$this->applyWhere($filter->getColumn(), $value_to, '<=');
		}
	}


	/**
	 * Filter by keyword
	 * @param  Filter\FilterText $filter
	 * @return void
	 */
	public function applyFilterText(Filter\FilterText $filter)
	{
		$or = [];
		$args = [];
		$big_or = '(';
		$big_or_args = [];
		$condition = $filter->getCondition();

		foreach ($condition as $column => $value) {
			$words = explode(' ', $value);

			$like = '(';
			$args = [];

			foreach ($words as $word) {
				$like .= "$column LIKE ? OR ";
				$args[] = "%$word%";
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

		$this->sql = $this->queryHelper->whereSql($or);

		foreach ($args as $arg) {
			$this->query_parameters[] = $arg;
		}
	}


	/**
	 * Filter by select value
	 * @param  Filter\FilterSelect $filter
	 * @return void
	 */
	public function applyFilterSelect(Filter\FilterSelect $filter)
	{
		foreach ($filter->getCondition() as $column => $value) {
			$this->applyWhere($column, $value);
		}
	}

	/**
	 * Filter by multiselect value
	 * @param  Filter\FilterMultiSelect $filter
	 * @return void
	 */
	public function applyFilterMultiSelect(Filter\FilterMultiSelect $filter)
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
		
		$this->sql = $this->queryHelper->whereSql($or);

		foreach ($args as $arg) {
			$this->query_parameters[] = $arg;
		}
	}

	/**
	 * Apply limit and offet on data
	 * @param int $offset
	 * @param int $limit
	 * @return static
	 */
	public function limit($offset, $limit)
	{
		$sql = $this->queryHelper->limit($limit, $offset);

		$this->data = $this->query($sql)->fetchAll();

		return $this;
	}


	/**
	 * Sort data
	 * @param  Sorting $sorting
	 * @return static
	 */
	public function sort(Sorting $sorting)
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

		if (!empty($sort)) {
			foreach ($sort as $column => $order) {
				$this->sql = $this->queryHelper->orderBy($column, $order);
			}
		}

		return $this;
	}

}
