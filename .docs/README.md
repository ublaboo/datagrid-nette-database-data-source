# Contributte\datagrid-nette-database-data-source

## Content

- [Usage - how use it](#usage)

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
