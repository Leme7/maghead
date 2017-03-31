<?php

namespace Maghead\Testing;

use Maghead\Manager\DataSourceManager;
use Maghead\TableBuilder\TableBuilder;
use Maghead\Runtime\BaseModel;
use Maghead\ConfigLoader;
use Maghead\Generator\Schema\SchemaGenerator;
use Maghead\Schema\DeclareSchema;
use Maghead\Runtime\BaseCollection;
use Maghead\Runtime\Result;
use Maghead\Bootstrap;
use Maghead\PDOExceptionPrinter;
use SQLBuilder\Driver\BaseDriver;
use PHPUnit\Framework\TestCase;
use CLIFramework\Logger;
use PDO;
use PDOException;
use Exception;

abstract class BaseTestCase extends TestCase
{
    /**
     * @var string $driver name
     *
     * This is used for filtering test cases for specific database driver. e.g. sqlite, mysql, pgsql... etc
     */
    protected $driver = 'sqlite';

    /**
     * @var string
     *
     * The data source id for creating default connection.
     * by default, $this->driver will be the default data source.
     */
    protected $defaultDataSource;


    /**
     * @var string
     *
     * This is used for filtering test cases for specific database driver. e.g. sqlite, mysql, pgsql... etc
     */
    protected $onlyDriver;


    protected $connManager;

    protected $config;

    /**
     * @var Maghead\Connection
     *
     * The default connection object.
     */
    protected $conn;

    protected $allowConnectionFailure = false;

    protected $freeConnections = true;

    /**
     * @var Maghead\QueryDriver
     *
     * The query driver object of the default connection.
     */
    protected $queryDriver;


    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        if (!extension_loaded('pdo')) {
            return $this->markTestSkipped('pdo extension is required for model testing');
        }

        $this->connManager = DataSourceManager::getInstance();
        $this->logger = new Logger();
        $this->logger->setQuiet();
        $this->config = $this->loadConfig();
    }

    protected function getMasterDataSourceId()
    {
        if ($this->defaultDataSource) {
            return $this->defaultDataSource;
        }
        return $this->getCurrentDriverType();
    }

    /**
     * By overriding the DB environment variable, we can test specific test suites.
     */
    protected function getCurrentDriverType()
    {
        return getenv('DB') ?: $this->driver;
    }

    /**
     * by default we load the config from symbolic file. (this will be created
     * by the bootstrap script)
     */
    protected function loadConfig()
    {
        $config = ConfigLoader::loadFromSymbol(true);
        $config->setMasterDataSourceId($this->getMasterDataSourceId());
        $config->setAutoId();
        return $config;
    }

    public function setUp()
    {
        if ($this->onlyDriver !== null && $this->getCurrentDriverType() != $this->onlyDriver) {
            return $this->markTestSkipped("{$this->onlyDriver} only");
        }

        // Always reset config from symbol file
        $this->config = $this->loadConfig();

        Bootstrap::setupDataSources($this->config, $this->connManager);
        Bootstrap::setupGlobalVars($this->config, $this->connManager);

        $this->prepareConnections();
    }

    public function tearDown()
    {
        if ($this->freeConnections) {
            $this->connManager->free();
            $this->conn = null;
        }
    }

    public static function tearDownAfterClass()
    {
    }

    protected function prepareConnections()
    {
        $this->setupDefaultConnection();
    }

    protected function getMasterConnection()
    {
        if (!$this->conn) {
            throw new Exception("The test case didn't setup the default connection.");
        }
        return $this->conn;
    }

    protected function setupDefaultConnection()
    {
        if (!$this->conn && $this->getMasterDataSourceId()) {
            $this->conn = $this->setupConnection($this->getMasterDataSourceId());
            $this->queryDriver = $this->conn->getQueryDriver();
        }
    }

    /**
     * @return Maghead\Connection
     */
    protected function setupConnection(string $connId)
    {
        try {
            // Create the default connection
            return $this->connManager->getConnection($connId);
        } catch (PDOException $e) {
            if ($this->allowConnectionFailure) {
                $this->markTestSkipped(
                    sprintf("Can not connect to database by data source '%s' message:'%s' config:'%s'",
                        $connId,
                        $e->getMessage(),
                        var_export($this->config->getDataSource($connId), true)
                    ));

                return;
            }
            fprintf(STDERR, "Can not connect to database by data source '%s' message:'%s' config:'%s'",
                $connId,
                $e->getMessage(),
                var_export($this->config->getDataSource($connId), true)
            );
            throw $e;
        }
    }


    /**
     * @return array[] class map
     */
    protected function updateSchemaFiles(DeclareSchema $schema)
    {
        $generator = new SchemaGenerator($this->config, $this->logger);

        return $generator->generate([$schema]);
    }

    protected function buildSchemaTable(PDO $conn, BaseDriver $driver, DeclareSchema $schema, array $options = ['rebuild' => true])
    {
        $builder = TableBuilder::create($driver, $options);
        $sqls = array_filter(array_merge($builder->prepare(), $builder->build($schema), $builder->finalize()));
        foreach ($sqls as $sql) {
            $conn->query($sql);
        }
    }

    public function matrixDataProvider(array $alist, array $blist)
    {
        $data = [];
        foreach ($alist as $a) {
            foreach ($blist as $b) {
                $data[] = [$a, $b];
            }
        }

        return $data;
    }

    public function driverTypeDataProvider()
    {
        $data = [];
        if (extension_loaded('pdo_mysql')) {
            $data[] = ['mysql'];
        }
        if (extension_loaded('pdo_pgsql')) {
            $data[] = ['pgsql'];
        }
        if (extension_loaded('pdo_sqlite')) {
            $data[] = ['sqlite'];
        }

        return $data;
    }

    public function getLogger()
    {
        return $this->logger;
    }

    public function getConfig()
    {
        return $this->config;
    }


    // ==========================================================
    // Assertion Methods
    // ==========================================================

    public function assertTableExists(PDO $conn, $tableName)
    {
        $driverName = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        switch ($driverName) {
            case 'mysql':
                $stm = $conn->query("SHOW COLUMNS FROM $tableName");
                break;
            case 'pgsql':
                $stm = $conn->query("SELECT * FROM information_schema.columns WHERE table_name = '$tableName';");
                break;
            case 'sqlite':
                $stm = $conn->query("select sql from sqlite_master where type = 'table' AND name = '$tableName'");
                break;
            default:
                throw new Exception('Unsupported PDO driver');
                break;
        }
        $result = $stm->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($result);
    }

    public function assertQueryOK(PDO $conn, $sql, $args = array())
    {
        try {
            $ret = $conn->query($sql);
            $this->assertNotNull($ret);

            return $ret;
        } catch (PDOException $e) {
            PDOExceptionPrinter::show($e, $sql, $args, new Logger());
            throw $e;
        }
    }

    public function successfulDelete(BaseModel $record)
    {
        $this->assertResultSuccess($record->delete());
    }

    public function assertResultFail(Result $ret, $message = null)
    {
        $this->assertTrue($ret->error, $message ?: $ret->message);
    }

    public function assertInstanceOfModel(BaseModel $record)
    {
        $this->assertInstanceOf('Maghead\Runtime\BaseModel', $record);
    }

    public function assertInstanceOfCollection(BaseCollection $collection)
    {
        $this->assertInstanceOf('Maghead\Runtime\BaseCollection', $collection);
    }

    public function assertCollectionSize($size, BaseCollection $collection, $message = null)
    {
        $this->assertEquals($size, $collection->size(), $message ?: 'Colletion size should match');
    }

    public function assertRecordLoaded(BaseModel $record, $message = null)
    {
        $data = $record->getStashedData();
        $this->assertNotEmpty($data, $message ?: 'Record loaded');
    }

    public function assertResultSuccess(Result $ret, $message = null)
    {
        if ($ret->error === true) {
            // Pretty printing this
            var_dump($ret);
        }
        $this->assertFalse($ret->error, $message ?: $ret->message);
    }

    public function resultOK($expect, Result $ret)
    {
        $this->assertNotNull($ret);
        if ($ret->success === $expect) {
            $this->assertTrue($ret->success, $ret->message);
        } else {
            var_dump($ret->sql);
            echo $ret->exception;
            $this->assertTrue($ret->success, $ret->message);
        }
    }
}
