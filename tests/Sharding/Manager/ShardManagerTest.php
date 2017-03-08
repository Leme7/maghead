<?php
use Maghead\Testing\ModelTestCase;
use Maghead\Sharding\Manager\ShardManager;
use Maghead\ConfigLoader;
use StoreApp\Model\{Store, StoreSchema};

/**
 * @group sharding
 * @group manager
 */
class ShardManagerTest extends ModelTestCase
{
    protected $defaultDataSource = 'node1';

    protected $requiredDataSources = ['node1', 'node1_2', 'node2', 'node2_2'];

    public function getModels()
    {
        return [new \StoreApp\Model\StoreSchema];
    }

    protected function loadConfig()
    {
        $config = ConfigLoader::loadFromArray([
            'cli' => ['bootstrap' => 'vendor/autoload.php'],
            'schema' => [
                'auto_id' => true,
                'base_model' => '\\Maghead\\Runtime\\BaseModel',
                'base_collection' => '\\Maghead\\Runtime\\BaseCollection',
                'paths' => ['tests'],
            ],
            'sharding' => [
                'mappings' => [
                    'M_store_id' => \StoreApp\Model\StoreShardMapping::config(),
                ],
                // Shards pick servers from nodes config, HA groups
                'shards' => [
                    's1' => [
                        'write' => [
                          'node1_2' => ['weight' => 0.1],
                        ],
                        'read' => [
                          'node1'   =>  ['weight' => 0.1],
                          'node1_2' => ['weight' => 0.1],
                        ],
                    ],
                    's2' => [
                        'write' => [
                          'node2_2' => ['weight' => 0.1],
                        ],
                        'read' => [
                          'node2'   =>  ['weight' => 0.1],
                          'node2_2' => ['weight' => 0.1],
                        ],
                    ],
                ],
            ],
            // data source is defined for different data source connection.
            'data_source' => [
                'master' => 'node1',
                'nodes' => [
                    'node1' => [
                        'dsn' => 'sqlite::memory:',
                        'query_options' => ['quote_table' => true],
                        'driver' => 'sqlite',
                        'connection_options' => [],
                    ],
                    'node1_2' => [
                        'dsn' => 'sqlite::memory:',
                        'query_options' => ['quote_table' => true],
                        'driver' => 'sqlite',
                        'connection_options' => [],
                    ],
                    'node2' => [
                        'dsn' => 'sqlite::memory:',
                        'query_options' => ['quote_table' => true],
                        'driver' => 'sqlite',
                        'connection_options' => [],
                    ],
                    'node2_2' => [
                        'dsn' => 'sqlite::memory:',
                        'query_options' => ['quote_table' => true],
                        'driver' => 'sqlite',
                        'connection_options' => [],
                    ],
                ],
            ],
        ]);
        return $config;
    }

    public function testGetMappingById()
    {
        $shardManager = new ShardManager($this->config, $this->connManager);
        $mapping = $shardManager->getShardMapping('M_store_id');
        $this->assertNotEmpty($mapping);
    }

    public function testGetShards()
    {
        $shardManager = new ShardManager($this->config, $this->connManager);
        $shards = $shardManager->getShardsOf('M_store_id');
        $this->assertNotEmpty($shards);
    }

    public function testCreateShardDispatcher()
    {
        $shardManager = new ShardManager($this->config, $this->connManager);
        $dispatcher = $shardManager->createShardDispatcherOf('M_store_id');
        $this->assertNotNull($dispatcher);
        return $dispatcher;
    }

    /**
     * @depends testCreateShardDispatcher
     */
    public function testDispatchRead($dispatcher)
    {
        $shard = $dispatcher->dispatch('3d221024-eafd-11e6-a53b-3c15c2cb5a5a');
        $this->assertInstanceOf('Maghead\\Sharding\\Shard', $shard);

        $repo = $shard->createRepo('StoreApp\\Model\\StoreRepo');
        $this->assertInstanceOf('Maghead\\Runtime\\BaseRepo', $repo);
        $this->assertInstanceOf('StoreApp\\Model\\StoreRepo', $repo);
    }

    /**
     * @depends testCreateShardDispatcher
     */
    public function testDispatchWrite($dispatcher)
    {
        $shard = $dispatcher->dispatch('3d221024-eafd-11e6-a53b-3c15c2cb5a5a');
        $this->assertInstanceOf('Maghead\\Sharding\\Shard', $shard);

        $repo = $shard->createRepo('StoreApp\\Model\\StoreRepo');
        $this->assertInstanceOf('Maghead\\Runtime\\BaseRepo', $repo);
        $this->assertInstanceOf('StoreApp\\Model\\StoreRepo', $repo);
        return $repo;
    }


    /**
     * @depends testDispatchWrite
     */
    public function testWriteRepo($repo)
    {
        $ret = $repo->create([ 'name' => 'My Store', 'code' => 'MS001' ]);
        $this->assertResultSuccess($ret);
    }

    public function testRequiredField()
    {
        $store = new Store;
        $ret = $store->create([ 'name' => 'testapp', 'code' => 'testapp' ]);
        $this->assertResultSuccess($ret);
    }

    public function testCreateWithRequiredFieldNull()
    {
        $store = new Store;
        $ret = $store->create([ 'name' => 'testapp', 'code' => null ]);
        $this->assertResultFail($ret);
    }

    public function testUpdateWithRequiredFieldNull()
    {
        $store = Store::createAndLoad([ 'name' => 'testapp', 'code' => 'testapp' ]);
        $this->assertNotFalse($store);

        $ret = $store->update([ 'name' => 'testapp', 'code' => null ]);
        $this->assertResultFail($ret);

        $ret = $store->update([ 'name' => 'testapp 2' ]);
        $this->assertResultSuccess($ret);
        $this->assertEquals('testapp 2', $store->name);
    }


}
