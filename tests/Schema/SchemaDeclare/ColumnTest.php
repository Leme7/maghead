<?php
use Maghead\Schema\DeclareSchema;
use Maghead\Schema\DeclareColumn;

/**
 * @group schema
 */
class DeclareColumnTest extends PHPUnit\Framework\TestCase
{
    public function test()
    {
        $column = new DeclareColumn(new DeclareSchema, 'foo');
        $column->primary()
            ->integer()
            ->autoIncrement()
            ->notNull();
        $this->assertEquals('foo', $column->name);
        $this->assertTrue($column->primary);
        $this->assertEquals('int', $column->type);
        $this->assertTrue($column->notNull);
    }
}
