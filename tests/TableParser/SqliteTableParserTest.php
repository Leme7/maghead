<?php
use SQLBuilder\Driver\PDOSQLiteDriver;
use Maghead\TableParser\SqliteTableParser;

/**
 * @group table-parser
 */
class SqliteTableParserTest extends PHPUnit\Framework\TestCase
{



    public function testParsingQuotedIdentifier()
    {
        $conn = new PDO('sqlite::memory:');
        $defsql = "CREATE TABLE foo (`uuid` BINARY(16) NOT NULL PRIMARY KEY, NAME varchar(12))";
        $conn->query($defsql);

        $parser = new SqliteTableParser($conn, new PDOSQLiteDriver($conn));
        $tables = $parser->getTables();

        $sql = $parser->getTableSql('foo');
        $this->assertEquals($defsql, $sql);
        $columns = $parser->parseTableSql('foo');
        $this->assertNotEmpty($columns);
        var_dump($columns);
    }


    public function testSQLiteTableParser()
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->query('CREATE TABLE foo ( id integer primary key autoincrement, name varchar(12), phone varchar(32) unique , address text not null );');
        $pdo->query('CREATE TABLE bar ( id integer primary key autoincrement, confirmed boolean default false, content blob );');

        $parser = new SqliteTableParser($pdo, new PDOSQLiteDriver($pdo));
        $tables = $parser->getTables();

        $this->assertNotEmpty($tables);
        $this->assertCount(2, $tables);

        $sql = $parser->getTableSql('foo');
        $columns = $parser->parseTableSql('foo');
        $this->assertNotEmpty($columns);

        $columns = $parser->parseTableSql('bar');
        $this->assertNotEmpty($columns);

        $schema = $parser->reverseTableSchema('bar');
        $this->assertNotNull($schema);

        $id = $schema->getColumn('id');
        $this->assertNotNull($id);
        $this->assertTrue($id->autoIncrement);
        $this->assertEquals('INT', $id->type);
        $this->assertEquals('int', $id->isa);
        $this->assertTrue($id->primary);
    }
}
