<?php

declare(strict_types=1);

namespace Ublaboo\DataGrid\Tests\Cases;

use Nette\Caching\Storages\DevNullStorage;
use Nette\Database\Connection;
use Nette\Database\Context;
use Nette\Database\Structure;
use Tester\Assert;
use Tester\TestCase;
use Ublaboo;
use Ublaboo\DataGrid\DataGrid;
use Ublaboo\DataGrid\Filter\FilterDate;
use Ublaboo\DataGrid\Filter\FilterDateRange;
use Ublaboo\DataGrid\Filter\FilterRange;
use Ublaboo\DataGrid\Filter\FilterSelect;
use Ublaboo\DataGrid\Filter\FilterText;
use Ublaboo\DataGrid\Utils\Sorting;
use Ublaboo\NetteDatabaseDataSource\NetteDatabaseDataSource;

require __DIR__ . '/../bootstrap.php';

final class NetteDatabaseDataSourceTest extends TestCase
{

	/**
	 * @var Context
	 */
	private $db;

	/**
	 * @var Ublaboo\DataGrid\DataGrid
	 */
	private $grid;

	public function setUp(): void
	{
		$connection = new Connection('.', null, null, ['lazy' => true]);

		$structure = new Structure($connection, new DevNullStorage());
		$this->db = new Context($connection, $structure);

		$this->grid = new DataGrid;
	}


	public function testQuery(): void
	{
		$s = new NetteDatabaseDataSource($this->db, 'SELECT * FROM user');

		$s->filterOne(['id' => 1]);
		$q = $s->getQuery();

		Assert::same('SELECT * FROM user WHERE id = ?', $q[0]);
		Assert::same([1], $q[1]);
	}


	public function testSort(): void
	{
		$s = new NetteDatabaseDataSource($this->db, 'SELECT * FROM user');
		$sorting = new Sorting(['user.name' => 'DESC']);

		$s->sort($sorting);
		$q = $s->getQuery();

		Assert::same('SELECT * FROM user ORDER BY user.name DESC', $q[0]);
	}


	public function testApplyFilterSelect(): void
	{
		$s = new NetteDatabaseDataSource($this->db, 'SELECT * FROM user');
		$filter = new FilterSelect($this->grid, 'status', 'Status', [1 => 'Online', 0 => 'Offline'], 'user.status');
		$filter->setValue(1);

		$s->filter([$filter]);
		$q = $s->getQuery();

		Assert::same('SELECT * FROM user WHERE user.status = ?', $q[0]);
		Assert::same([1], $q[1]);
	}

	public function testApplyFilterText(): void
	{
		$s = new NetteDatabaseDataSource($this->db, 'SELECT * FROM user');
		$filter = new FilterText($this->grid, 'name', 'Name', ['name']);
		$filter->setValue('text');
		$s->filter([$filter]);
		$q = $s->getQuery();

		Assert::same('SELECT * FROM user WHERE (name LIKE ?)', $q[0]);
		Assert::same(['%text%'], $q[1]);
	}

	public function testApplyFilterTextDouble(): void
	{
		$s = new NetteDatabaseDataSource($this->db, 'SELECT * FROM user');
		$filter = new FilterText($this->grid, 'name', 'Name or id', ['name', 'id']);
		$filter->setValue('text');
		$s->filter([$filter]);
		$q = $s->getQuery();

		Assert::same('SELECT * FROM user WHERE ((name LIKE ?) OR (id LIKE ?))', $q[0]);
		Assert::same(['%text%', '%text%'], $q[1]);
	}

	public function testApplyFilterTextSplitWordsSearch(): void
	{
		$s = new NetteDatabaseDataSource($this->db, 'SELECT * FROM user');
		$filter = new FilterText($this->grid, 'name', 'Name or id', ['name', 'id']);
		$filter->setValue('text alternative');
		$s->filter([$filter]);
		$q = $s->getQuery();

		Assert::same('SELECT * FROM user WHERE ((name LIKE ? OR name LIKE ?) OR (id LIKE ? OR id LIKE ?))', $q[0]);
		Assert::same(['%text%', '%alternative%', '%text%', '%alternative%'], $q[1]);
	}

	public function testApplyFilterTextSplitWordsSearchDisabled(): void
	{
		$s = new NetteDatabaseDataSource($this->db, 'SELECT * FROM user');
		$filter = new FilterText($this->grid, 'name', 'Name or id', ['name', 'id']);
		$filter->setValue('text alternative');
		$filter->setSplitWordsSearch(false);
		$s->filter([$filter]);
		$q = $s->getQuery();

		Assert::same('SELECT * FROM user WHERE ((name LIKE ?) OR (id LIKE ?))', $q[0]);
		Assert::same(['%text alternative%', '%text alternative%'], $q[1]);
	}

	public function testApplyFilterTextExactSearch(): void
	{
		$s = new NetteDatabaseDataSource($this->db, 'SELECT * FROM user');
		$filter = new FilterText($this->grid, 'name', 'Name or id', ['name', 'id']);
		$filter->setValue('text');
		$filter->setExactSearch();
		$s->filter([$filter]);
		$q = $s->getQuery();

		Assert::same('SELECT * FROM user WHERE ((name = ?) OR (id = ?))', $q[0]);
		Assert::same(['text', 'text'], $q[1]);
	}

	public function testApplyFilterTextSplitWordsSearchDisabledExact(): void
	{
		$s = new NetteDatabaseDataSource($this->db, 'SELECT * FROM user');
		$filter = new FilterText($this->grid, 'name', 'Name or id', ['name', 'id']);
		$filter->setValue('text with space');
		$filter->setSplitWordsSearch(false);
		$filter->setExactSearch();
		$s->filter([$filter]);
		$q = $s->getQuery();

		Assert::same('SELECT * FROM user WHERE ((name = ?) OR (id = ?))', $q[0]);
		Assert::same(['text with space', 'text with space'], $q[1]);
	}

	public function testComplexQuery(): void
	{
		$q =
			'SELECT u.name, u.age, p.name, p.surname, p2.name, p2.surname CASE WHEN p3.age THEN p3.age ELSE 8 END
			FROM user u
			LEFT JOIN parent p
				ON p.id = u.mother_id
			LEFT JOIN parent p2
				ON p2.id = u.father_id
			JOIN (SELECT id, age FROM parent) p3
				ON p3.age = u.age
			WHERE p2.id != 2 OR p2.id NOT IN (?, ?)';

		$s = new NetteDatabaseDataSource($this->db, $q, [3, 4]);

		$filter1 = new FilterSelect($this->grid, 'status', 'Status', [1 => 'Online', 0 => 'Offline'], 'user.status');
		$filter1->setValue(1);

		$filter2 = new FilterText($this->grid, 'name', 'Name or id', ['name', 'id']);
		$filter2->setValue('text');

		$filter3 = new FilterRange($this->grid, 'range', 'Range', 'id', 'To');
		$filter3->setValue(['from' => 2, 'to' => null]);

		$filter4 = new FilterDateRange($this->grid, 'date range', 'Date Range', 'created', '-');
		$filter4->setValue(['from' => '1. 2. 2003', 'to' => '3. 12. 2149']);

		$filter5 = new FilterDate($this->grid, 'date', 'Date', 'date');
		$filter5->setValue('12. 12. 2012');

		$filter6 = new FilterRange($this->grid, 'range', 'Range', 'id', 'To');
		$filter6->setValue(['from' => '', 'to' => 0]);

		$s->filter([
			$filter1,
			$filter2,
			$filter3,
			$filter4,
			$filter5,
			$filter6
		]);

		$q = $s->getQuery();

		$expected_query =
			'SELECT u.name, u.age, p.name, p.surname, p2.name, p2.surname CASE WHEN p3.age THEN p3.age ELSE 8 END
			FROM user u
			LEFT JOIN parent p
				ON p.id = u.mother_id
			LEFT JOIN parent p2
				ON p2.id = u.father_id
			INNER JOIN (SELECT id, age FROM parent) p3
				ON p3.age = u.age
			WHERE (p2.id != 2 OR p2.id NOT IN (?, ?))
				AND user.status = ?
				AND ((name LIKE ?) OR (id LIKE ?))
				AND id >= ?
				AND DATE(created) >= ?
				AND DATE(created) <= ?
				AND DATE(date) = ?
				AND id <= ?
			';

		Assert::same(trim(preg_replace('/\s+/', ' ', $expected_query)), $q[0]);

		$expectedParams = [
			3,
			4,
			1,
			'%text%',
			'%text%',
			2,
			'2003-02-01',
			'2149-12-03',
			'2012-12-12',
			0,
		];

		Assert::same($expectedParams, $q[1]);
	}
}

(new NetteDatabaseDataSourceTest)->run();
