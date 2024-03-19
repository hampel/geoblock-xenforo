<?php namespace Hampel\Geoblock;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
    use StepRunnerUpgradeTrait;

	public function install(array $stepParams = [])
	{
		$this->schemaManager()->createTable('xf_geoblock_cache', function(Create $table)
		{
			$table->addColumn('ip', 'varbinary', 16);
			$table->addColumn('iso_code', 'varchar', 2);
			$table->addColumn('name', 'varchar', 64);
			$table->addColumn('eu', 'tinyint')->setDefault(0);
			$table->addColumn('lookup_date', 'int');
			$table->addPrimaryKey('ip');
		});
	}

    // ################################ LEGACY UPGRADE FROM XF 1.5 "GeoIP" ##################

    public function upgrade1000070Step1()
    {
        // check for legacy upgrade from XF1
        if ($this->schemaManager()->tableExists('xf_ip_geo'))
        {
            $this->schemaManager()->renameTable('xf_ip_geo', 'xf_geoip_cache' );
            $this->schemaManager()->alterTable('xf_geoip_cache', function (Alter $table) {
                $table->addColumn('eu', 'tinyint')->setDefault(0);
                $table->changeColumn('ip', 'varbinary', 16);
                $table->changeColumn('lookup_date', 'int');
            });
        }
    }

    // ################################ UPGRADE TO 1.2.0 ##################

    public function upgrade1020070Step1()
    {
        // rename cache table so we have the addon id in the table name
        $this->schemaManager()->renameTable('xf_geoip_cache', 'xf_geoblock_cache' );
    }

    // ################################ Uninstall ##################

	public function uninstall(array $stepParams = [])
	{
		$this->schemaManager()->dropTable('xf_geoblock_cache');
	}

	public function checkRequirements(&$errors = [], &$warnings = [])
	{
		$vendorDirectory = sprintf("%s/vendor", $this->addOn->getAddOnDirectory());
		if (!file_exists($vendorDirectory))
		{
			$errors[] = "vendor folder does not exist - cannot proceed with addon install";
		}
	}
}