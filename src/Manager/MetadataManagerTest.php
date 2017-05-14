<?php

namespace Maghead\Manager;

use Maghead\Model\MetadataSchema;
use Maghead\Model\MetadataCollection;
use Maghead\Testing\ModelTestCase;

/**
 * @group manager
 */
class MetadataManagerTest extends ModelTestCase
{
    public function models()
    {
        return [ new MetadataSchema ];
    }

    public function testArrayAccessor()
    {
        $metadata = new MetadataManager($this->conn, $this->queryDriver);
        $metadata->init();
        $metadata['version'] = 1;
        $this->assertEquals(1, $metadata['version']);

        $metadata['version'] = 2;
        $this->assertEquals(2, $metadata['version']);

        $this->assertEquals(2, $metadata->getVersion());
        foreach ($metadata as $key => $value) {
            $this->assertTrue(!is_numeric($key));
            $this->assertNotNull($value);
        }
    }

    public function testMetadata()
    {
        $metadata = new MetadataManager($this->conn, $this->queryDriver);
        $metadata->init();

        $metaItem = new \Maghead\Model\Metadata;
        $schema = $metaItem->getSchema();
        $this->assertNotNull($schema);

        $ret = $metaItem->create(array('name' => 'version', 'value' => '0.1' ));
        $this->assertResultSuccess($ret);
    }

    public function testCollection()
    {
        $metadata = new MetadataManager($this->conn, $this->queryDriver);
        $metadata->init();
        $metadata['version'] = 1;
        $metadata['name'] = 'c9s';
        $metas = new MetadataCollection;
        foreach ($metas as $meta) {
            $this->assertNotNull($meta);
        }
    }
}
