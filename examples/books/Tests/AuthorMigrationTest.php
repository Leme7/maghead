<?php

namespace AuthorBooks\Tests;

use Maghead\Testing\ModelTestCase;
use AuthorBooks\Model\Author;
use AuthorBooks\Model\AuthorSchema;
use AuthorBooks\Model\AddressSchema;
use AuthorBooks\Model\AuthorCollection;
use Maghead\Migration\Migration;
use SQLBuilder\Universal\Syntax\Column;
use SQLBuilder\Driver\PDOMySQLDriver;
use SQLBuilder\Driver\PDOPgSQLDriver;
use SQLBuilder\Driver\SQLiteDriver;

use Maghead\Migration\AutomaticMigration;
use GetOptionKit\OptionResult;
use GetOptionKit\OptionCollection;

/**
 * @group mysql
 * @group migration
 */
class AuthorMigrationTest extends ModelTestCase
{
    public function models()
    {
        return [
            new AddressSchema,
            new AuthorSchema,
        ];
    }

    public function testImportSchema()
    {
        $schema = new AddressSchema;
        $this->dropSchemaTables([$schema]);

        $table = $schema->getTable();
        AutomaticMigration::options($options = new OptionCollection);
        $migrate = new AutomaticMigration(
            $this->conn,
            $this->queryDriver,
            $this->logger,
            OptionResult::create($options, [ ]));
        $migrate->upgrade([$schema]);
    }


    public function testModifyColumn()
    {
        $this->forDrivers('mysql');

        $schema = new AuthorSchema;
        $schema->getColumn('email')
            ->varchar(20)
            ->notNull()
            ;
        $this->buildSchemaTables([$schema], true);
        AutomaticMigration::options($options = new OptionCollection);
        $migrate = new AutomaticMigration(
            $this->conn,
            $this->queryDriver,
            $this->logger,
            OptionResult::create($options, [ ]));
        $migrate->upgrade([$schema]);
    }


    public function testAddColumn()
    {
        // pgsql: multiple primary keys for table "authors" are not allowed
        $this->skipDrivers('pgsql');

        $schema = new AuthorSchema;
        $this->dropSchemaTables([$schema]);

        $schema->removeColumn('email');

        $this->buildSchemaTables([$schema], true);

        AutomaticMigration::options($options = new OptionCollection);

        $migrate = new AutomaticMigration(
            $this->conn,
            $this->queryDriver,
            $this->logger,
            OptionResult::create($options, [ ]));

        $migrate->upgrade([$schema]);
    }

    public function testRemoveColumn()
    {
        $this->forDrivers('mysql');

        $schema = new AuthorSchema;
        $schema->column('cellphone')
            ->varchar(30);
        $this->buildSchemaTables([$schema], true);
        AutomaticMigration::options($options = new OptionCollection);
        $migrate = new AutomaticMigration(
            $this->conn,
            $this->queryDriver,
            $this->logger,
            OptionResult::create($options, [ ]));
        $migrate->upgrade([$schema]);
    }
}
