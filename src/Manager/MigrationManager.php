<?php
namespace Maghead\Manager;

use Maghead\Manager\MetadataManager;
use Maghead\Manager\DataSourceManager;
use Maghead\Migration\MigrationLoader;
use Maghead\Migration\MigrationRunner;
use Maghead\Migration\AutomaticMigration;
use Maghead\Runtime\Connection;
use GetOptionKit\OptionResult;
use CLIFramework\Logger;
use Magsql\Driver\BaseDriver;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Top layer API for migration
 */
class MigrationManager
{
    protected $dataSourceManager;

    protected $logger;

    public function __construct(DataSourceManager $dataSourceManager, Logger $logger)
    {
        $this->dataSourceManager = $dataSourceManager;
        $this->logger = $logger;
    }

    public function upgrade(array $ids = null, $steps = 1)
    {
        if (!$ids) {
            $ids = $this->dataSourceManager->getNodeIds();
        }
        foreach ($ids as $id) {
            $this->logger->info("Performing upgrade on node $id");
            $conn = $this->dataSourceManager->getConnection($id);
            $driver = $conn->getQueryDriver();

            $scripts = MigrationLoader::getDeclaredMigrationScripts();

            $runner = new MigrationRunner($conn, $driver, $this->logger, $scripts);
            $runner->runUpgrade();

            $this->logger->info("node $id is successfully migrated.");
        }
    }

    public function downgrade(array $ids = null, $steps = 1)
    {
        if (!$ids) {
            $ids = $this->dataSourceManager->getNodeIds();
        }
        foreach ($ids as $id) {
            $this->logger->info("Performing downgrade on node $id");
            $conn = $this->dataSourceManager->getConnection($id);
            $driver = $conn->getQueryDriver();

            $scripts = MigrationLoader::getDeclaredMigrationScripts();

            $runner = new MigrationRunner($conn, $driver, $this->logger, $scripts);
            $runner->runDowngrade($steps);

            $this->logger->info("node $id is successfully migrated.");
        }
    }

    public function upgradeAutomatically(array $ids = null, array $schemas, OptionResult $options = null)
    {
        if (!$ids) {
            $ids = $this->dataSourceManager->getNodeIds();
        }

        foreach ($ids as $id) {
            $this->logger->info("Performing automatic upgrade on node $id");

            $conn = $this->dataSourceManager->getConnection($id);
            $driver = $conn->getQueryDriver();

            $script = new AutomaticMigration($conn, $driver, $this->logger, $options);
            try {
                $this->logger->info('Begining transaction...');
                $conn->beginTransaction();

                // where to find the schema?
                $script->upgrade($schemas);

                $this->logger->info('Committing...');
                $conn->commit();
            } catch (Exception $e) {
                $this->logger->error('Exception was thrown: '.$e->getMessage());
                $this->logger->warn('Rolling back ...');
                $conn->rollback();
                $this->logger->warn('Recovered, escaping...');
                throw $e;
            }

            $this->logger->info("node $id is successfully migrated.");
        }
    }
}
