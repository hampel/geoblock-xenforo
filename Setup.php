<?php namespace Hampel\Geoblock;

use XF\AddOn\AbstractSetup;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
	public function install(array $stepParams = [])
	{
		$this->schemaManager()->createTable('xf_geoip_cache', function(Create $table)
		{
			$table->addColumn('ip', 'varbinary', 16);
			$table->addColumn('iso_code', 'varchar', 2);
			$table->addColumn('name', 'varchar', 64);
			$table->addColumn('eu', 'tinyint')->setDefault(0);
			$table->addColumn('lookup_date', 'int');
			$table->addPrimaryKey('ip');
		});
	}

	public function upgrade(array $stepParams = [])
	{
		// Nothing to do yet
	}

	public function uninstall(array $stepParams = [])
	{
		$this->schemaManager()->dropTable('xf_geoip_cache');
	}
}