[![Build Status](https://travis-ci.org/contributte/datagrid-nette-database-data-source.svg?branch=master)](https://travis-ci.org/contributte/datagrid-nette-database-data-source)
[![Latest Stable Version](https://poser.pugx.org/contributte/datagrid-nette-database-data-source/v/stable)](https://packagist.org/packages/contributte/datagrid-nette-database-data-source)
[![License](https://poser.pugx.org/contributte/datagrid-nette-database-data-source/license)](https://packagist.org/packages/contributte/datagrid-nette-database-data-source)
[![Total Downloads](https://poser.pugx.org/contributte/datagrid-nette-database-data-source/downloads)](https://packagist.org/packages/contributte/datagrid-nette-database-data-source)
[![Gitter](https://img.shields.io/gitter/room/nwjs/nw.js.svg)](https://gitter.im/ublaboo/help)

# Nette\Database data source for Nette\Database

Utility that makes possible to use Nette\Database native query with Ublaboo\DataGrid

If you are using `Nette\Database` instead of `Nette\Database\Table` (probably because of the need to create more complex queries), there was an option to call `ResultSet::fetchAll()` and operate with that array.

But why should you fetch `all` data from the database to show just a few of them?

## Installation

Download this package using composer:

```
composer require ublaboo/datagrid-nette-database-data-source
```

## Usage

```php
/**
 * @var Nette\Database\Context
 * @inject
 */
public $ndb;


public function createComponentNetteGrid($name)
{
	/**
	 * @type Ublaboo\DataGrid\DataGrid
	 */
	$grid = new DataGrid($this, $name);

	$query = 
		'SELECT p.*, GROUP_CONCAT(v.code SEPARATOR ", ") AS variants
		FROM product p
		LEFT JOIN product_variant p_v
			ON p_v.product_id = p.id
		WHERE p.deleted IS NULL
			AND (product.status = ? OR product.status = ?)';

	$params = [1, 2];

	/**
	 * @var Ublaboo\NetteDatabaseDataSource\NetteDatabaseDataSource
	 * 
	 * @param Nette\Database\Context
	 * @param $query
	 * @param $params|NULL
	 */
	$datasource = new NetteDatabaseDataSource($this->ndb, $query, $params);

	$grid->setDataSource($datasource);

	$grid->addColumnText('name', 'Name')
		->setSortable();

	$grid->addColumnNumber('id', 'Id')
		->setSortable();

	$grid->addColumnDateTime('created', 'Created');

	$grid->addFilterDateRange('created', 'Created:');

	$grid->addFilterText('name', 'Name and id', ['id', 'name']);

	$grid->addFilterSelect('status', 'Status', ['' => 'All', 1 => 'Online', 0 => 'Ofline', 2 => 'Standby']);

	/**
	 * Etc
	 */
}
```
