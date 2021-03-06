<?php

namespace Maghead\Schema;

use ReflectionObject;
use Exception;
use InvalidArgumentException;
use ReflectionClass;
use Maghead\Runtime\Config\FileConfigLoader;
use Maghead\Runtime\Config\Config;
use Maghead\Schema\Column\AutoIncrementPrimaryKeyColumn;
use Maghead\Schema\Column\UUIDPrimaryKeyColumn;
use Maghead\Schema\SchemaLoader;
use Maghead\Runtime\Bootstrap;
use Magsql\Universal\Query\CreateIndexQuery;
use Magsql\ParamMarker;
use Magsql\Universal\Query\SelectQuery;
use Magsql\Universal\Query\DeleteQuery;
use Maghead\Schema\Relationship\Relationship;
use Maghead\Schema\Relationship\HasOne;
use Maghead\Schema\Relationship\BelongsTo;
use Maghead\Schema\Relationship\HasMany;
use Maghead\Schema\Relationship\ManyToMany;
use Maghead\Exception\SchemaRelatedException;
use Maghead\Utils;

use Magsql\Driver\BaseDriver;
use Magsql\Driver\MySQLDriver;
use Magsql\Driver\PgSQLDriver;
use Magsql\Driver\SQLiteDriver;

use CodeGen\UserClass;
use CodeGen\ClassFile;

class ShardMappingMissingException extends SchemaRelatedException
{
}
class ShardKeyMissingException extends SchemaRelatedException
{
}

class MetaClass extends UserClass
{
}


class DeclareSchema extends BaseSchema implements Schema
{
    /**
     * column class alias table for quickly defining the column types.
     *
     * usage:
     *
     *    $this->column('item_uuid', 'uuid');
     *    $this->column('order_uuid', 'uuid-text');
     */
    public static $columnClassAliases = [
        // we only define the short class names so users can override the
        // implementation
        'uuid'           => 'UUIDColumn',
        'uuid-pk'        => 'UUIDPrimaryKeyColumn',

        'uuid-binary'    => 'UUIDColumn',
        'uuid-binary-pk' => 'UUIDPrimaryKeyColumn',

        'uuid-text'      => 'UUIDTextColumn',
        'uuid-text-pk'   => 'UUIDTextPrimaryKeyColumn',

        'ai-pk'   => 'AutoIncrementPrimaryKeyColumn',
    ];



    /**
     * @var array[string indexName] = CreateIndexQuery
     *
     * Indexes
     */
    public $indexes = [];

    public $onDelete;

    public $onUpdate;

    /**
     * @var string
     *
     * shard mapping Id
     *
     * This is used in sharded environment. By default every table is
     * local table.
     *
     */
    public $globalTable = false;

    public $shardMapping;

    public $enableHiddenPrimaryKey = true;

    /**
     * The table name here is dynamic, could be overrided.
     *
     * @var string table name
     */
    public $table;

    /**
     * Primary key name
     *
     * @var string primary key
     */
    public $primaryKey;


    /**
     * meta classes
     *
     * contains ClassFile[modal|collection|repo]
     */
    public $classes;


    /**
     * virtual schema (won't generate class files)
     */
    public $virtual = false;

    /**
     * Generate column accessors in the class.
     */
    public $enableColumnAccessors = true;


    private $relf;

    /**
     * Constructor of declare schema.
     *
     * The constructor calls `build` method to build the schema information.
     */
    public function __construct(array $options = array())
    {
        $this->classes = (object) [
            'baseModel'      => new ClassFile($this->getBaseModelClass()),
            'baseCollection' => new ClassFile($this->getBaseCollectionClass()),
            'baseRepo'       => new ClassFile($this->getBaseRepoClass()),
            'model'          => new ClassFile($this->getModelClass()),
            'collection'     => new ClassFile($this->getCollectionClass()),
            'repo'           => new ClassFile($this->getRepoClass())
        ];
        $this->build($options);
        $this->refl = new ReflectionObject($this);
    }

    /**
     * Build schema build the schema by running the "schema" method.
     *
     * The post process find the primary key from the built columns
     * And insert the auto-increment primary is auto_id config is enabled.
     */
    protected function build(array $options = array())
    {
        $this->schema($options);

        // postSchema is added for mixin that needs all schema information, for example LocalizeMixin
        foreach ($this->mixinSchemas as $mixin) {
            $mixin->postSchema();
        }

        $this->primaryKey = $this->findPrimaryKey();

        // if the primary key is not define, we should append the default primary key => id
        // AUTOINCREMENT is only allowed on an INTEGER PRIMARY KEY
        $config = Bootstrap::getConfig();
        if (false === $this->primaryKey && $this->enableHiddenPrimaryKey) {
            if ($config && $config->hasAutoId()) {
                $this->tryInsertPrimaryKeyColumn($config);
            }
        }

        $this->resolveRelationshipKeys();
    }

    protected function resolveRelationshipKeys()
    {
        foreach ($this->relations as $rel) {
            if ($rel instanceof ManyToMany) {
                continue;
            }
            if (!$rel['foreign_column']) {
                $schema = SchemaLoader::load($rel['foreign_schema']);
                $rel['foreign_column'] = $schema->getPrimaryKey();
            }
        }
    }

    protected function tryInsertPrimaryKeyColumn(Config $config)
    {
        $column = $config->createPrimaryKeyColumn($this, 'id');
        $this->primaryKey = $column->name;
        $this->insertColumn($column);
        return $column;
    }

    public function schema()
    {
    }

    public function getWriteSourceId()
    {
        if ($this->writeSourceId) {
            return $this->writeSourceId;
        }

        return Config::DEFAULT_DATASOURCE_ID;
    }

    public function getReadSourceId()
    {
        if ($this->readSourceId) {
            return $this->readSourceId;
        }

        return Config::DEFAULT_DATASOURCE_ID;
    }

    /**
     * resolve the column alias name to class name
     */
    protected static function resolveColumnAlias($alias)
    {
        if (isset(static::$columnClassAliases[$alias])) {
            return static::$columnClassAliases[$alias];
        }

        return $alias;
    }


    /**
     * Define new column object.
     *
     * @param string $name  column name
     * @param string $class column class name
     *
     * @return DeclareColumn
     */
    public function column($name, $class = 'Maghead\\Schema\\DeclareColumn')
    {
        if (isset($this->columns[$name])) {
            throw new Exception("column $name of ".get_class($this).' is already defined.');
        }
        $this->columnNames[] = $name;

        $class = $this->resolveColumnAlias($class);

        $class = Utils::resolveClass($class, ['Maghead\\Schema\\Column'], $this, ['Column']);
        return $this->columns[$name] = new $class($this, $name);
    }

    /**
     * SchemaGenerator generates column accessor methods from the
     * column definition automatically.
     *
     * If you don't want these accessors to be generated, you may simply call
     * 'disableColumnAccessors'
     */
    protected function disableColumnAccessors()
    {
        $this->enableColumnAccessors = false;
    }

    public function getColumns($includeVirtual = false)
    {
        if ($includeVirtual) {
            return $this->columns;
        }

        $columns = array();
        foreach ($this->columns as $name => $column) {
            // skip virtal columns
            if ($column->virtual) {
                continue;
            }
            $columns[ $name ] = $column;
        }

        return $columns;
    }

    public function getColumnLabels($includeVirtual = false)
    {
        $labels = array();
        foreach ($this->columns as $column) {
            if (!$includeVirtual && $column->virtual) {
                continue;
            }
            if ($column->label) {
                $labels[] = $column->label;
            }
        }

        return $labels;
    }

    /**
     * @param bool $includeVirtual
     *
     * @return string[]
     */
    public function getColumnNames($includeVirtual = false)
    {
        if ($includeVirtual) {
            return array_keys($this->columns);
        }

        $names = array();
        foreach ($this->columns as $name => $column) {
            if ($column->virtual) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * 'getColumn' gets the column object by the given column name.
     *
     * @param string $name
     */
    public function getColumn($name)
    {
        if (isset($this->columns[ $name ])) {
            return $this->columns[ $name ];
        }
    }

    public function removeColumn($columnName)
    {
        unset($this->columns[$columnName]);
        $this->columnNames = array_filter($this->columnNames, function ($n) use ($columnName) {
            return $n !== $columnName;
        });
    }

    /**
     * hasColumn method returns true if a column name is defined.
     *
     * @param string $name
     *
     * @return bool
     */
    public function hasColumn($name)
    {
        return isset($this->columns[ $name ]);
    }

    /**
     * Insert column object at the begining of the column list.
     *
     * @param DeclareColumn $column
     */
    public function insertColumn(DeclareColumn $column)
    {
        array_unshift($this->columnNames, $column->name);
        $this->columns = [$column->name => $column] + $this->columns;
    }

    /**
     * Find primary key from columns.
     *
     * This method will be called after building schema information to save the
     * primary key name
     *
     * @return string the identity of the key
     */
    public function findPrimaryKey()
    {
        foreach ($this->columns as $name => $column) {
            if ($column->primary) {
                return $name;
            }
        }

        return false;
    }

    /**
     * Find primary key column object from columns.
     *
     * This method will be called after building schema information to save the
     * primary key name
     *
     * @return DeclareColumn
     */
    public function findPrimaryKeyColumn()
    {
        foreach ($this->columns as $name => $column) {
            if ($column->primary) {
                return $column;
            }
        }

        return false;
    }

    /**
     * Find the global primary key (UUID key)
     *
     * A global primary key is: binary(32) in string type without auto-increment.
     *
     * @return string the identity of the key.
     */
    public function findGlobalPrimaryKey()
    {
        foreach ($this->columns as $name => $c) {
            if ($c->primary && $c->isa === "str" && $c->notNull && !$c->autoIncrement) {
                return $name;
            }
        }
    }

    /**
     * Find the local primary key.
     *
     * A local primary is: integer type with auto-increment attribute.
     *
     * @return string the identity of the key.
     */
    public function findLocalPrimaryKey()
    {
        foreach ($this->columns as $name => $c) {
            if ($c->primary && $c->isa === "int" && $c->autoIncrement) {
                return $name;
            }
        }
    }


    public function getShardKey()
    {
        $config = Bootstrap::getConfig();

        // If sharding is not enabled, don't throw exception.
        if (!isset($config['sharding']) || !$this->shardMapping) {
            return null;
        }

        if (!isset($config['sharding']['mappings'][$this->shardMapping])) {
            throw new ShardMappingMissingException($this, "shard mapping '{$this->shardMapping}' is missing.");
        }
        $mapping = $config['sharding']['mappings'][$this->shardMapping];
        if (!isset($mapping['key'])) {
            throw new ShardKeyMissingException($this, "The shard key of '{$this->shardMapping}' is missing.");
        }
        return $mapping['key'];
    }


    public function export()
    {
        $columnArray = array();
        foreach ($this->columns as $name => $column) {
            // This idea is from:
            // http://search.cpan.org/~tsibley/Jifty-DBI-0.75/lib/Jifty/DBI/Schema.pm
            //
            // if the refer attribute is defined, we should create the belongsTo relationship
            if ($refer = $column->refer) {
                // remove _id suffix if possible
                $accessorName = preg_replace('#_id$#', '', $name);
                $schema = null;
                $schemaClass = $refer;

                // convert class name "Post" to "PostSchema"
                if (substr($refer, -strlen('Schema')) != 'Schema') {
                    if (class_exists($refer.'Schema', true)) {
                        $refer = $refer.'Schema';
                    }
                }

                if (!class_exists($refer)) {
                    throw new Exception("refer schema from '$refer' not found.");
                }

                $o = new $refer();
                // schema is defined in model
                $schemaClass = $refer;

                if (!isset($this->relations[$accessorName])) {
                    $this->belongsTo($accessorName, $schemaClass, 'id', $name);
                }
            }
            $columnArray[ $name ] = $column->export();
        }

        return array(
            'label' => $this->getLabel(),
            'table' => $this->getTable(),
            'column_data' => $columnArray,
            'column_names' => $this->columnNames,
            'primary_key' => $this->primaryKey,
            'model_class' => $this->getModelClass(),
            'collection_class' => $this->getCollectionClass(),
            'relations' => $this->relations,
            'read_id' => $this->readSourceId,
            'write_id' => $this->writeSourceId,
        );
    }

    public function dump()
    {
        return var_export($this->export(), true);
    }

    /**
     * Use trait for the model class.
     *
     * @param string $class...
     *
     * @return string object
     */
    public function addModelTrait($traitClass)
    {
        $this->classes->baseModel->useTrait($traitClass);
    }


    /**
     * Implement interface in model class.
     *
     * @param string $class
     */
    public function addModelInterface($iface)
    {
        $this->classes->baseModel->implementInterface($iface);
    }

    /**
     * Use trait for the model class.
     *
     * @param string $class...
     *
     * @return string object
     */
    public function addCollectionTrait($traitClass)
    {
        $this->classes->baseCollection->useTrait($traitClass);
    }


    /**
     * Implement interface in collection class.
     *
     * @param string $class
     */
    public function addCollectionInterface($iface)
    {
        $this->classes->baseCollection->implementInterface($iface);
    }

    public function getShortClassName()
    {
        $strs = explode('\\', get_class($this));

        return end($strs);
    }

    public function getClassName()
    {
        return get_class($this);
    }

    public function getLabel()
    {
        return $this->label ?: $this->_modelClassToLabel();
    }

    /**
     * Get the table name of this schema class.
     *
     * @return string
     */
    public function getTable()
    {
        return $this->table ?: $this->_classnameToTable();
    }

    /**
     * Get the primary key column name.
     *
     * @return string primary key column name
     */
    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    /**
     * Get model class name of this schema.
     *
     * @return string model class name
     */
    public function getModelClass()
    {
        // If self class name is endded with 'Schema', remove it and return.
        $class = get_class($this);
        if (($p = strrpos($class, 'Schema')) !== false) {
            return substr($class, 0, $p);
        }
        // throw new Exception('Can not get model class from ' . $class );
        return $class;
    }

    /**
     * Convert current model name to a class name.
     *
     * @return string table name
     */
    protected function _classnameToTable()
    {
        return self::convertClassToTableName($this->getModelName());
    }


    public function addColumnFromArray(array $config)
    {
        $column = $this->column($config['name']);

        if (isset($config['required'])) {
            $column->required($config['required']);
        }

        if (isset($config['label'])) {
            $column->label($config['label']);
        }
        if (isset($config['renderAs'])) {
            $column->renderAs($config['renderAs']);
        }
        if (isset($config['type'])) {
            $column->type($config['type']);
        }
        if (isset($config['isa'])) {
            $column->isa($config['isa']);
        }

        return $column;
    }

    /**
     * Add column object into the column list.
     *
     * @param DeclareColumn
     *
     * @return DeclareColumn
     */
    public function addColumn(DeclareColumn $column)
    {
        if (isset($this->columns[$column->name])) {
            throw new Exception("column $name of ".get_class($this).' is already defined.');
        }
        $this->columnNames[] = $column->name;

        return $this->columns[ $column->name ] = $column;
    }

    protected function _modelClassToLabel()
    {
        /* Get the latest token. */
        if (preg_match('/(\w+)(?:Model)?$/', $this->getModelClass(), $reg)) {
            $label = @$reg[1];
            if (!$label) {
                throw new Exception('Table name error');
            }

            /* convert blah_blah to BlahBlah */
            return ucfirst(preg_replace('/[_]/', ' ', $label));
        }
    }

    public function __toString()
    {
        return get_class($this);
    }

    public function getMsgIds()
    {
        $ids = [];
        $ids[] = $this->getLabel();
        foreach ($this->getColumnLabels() as $label) {
            $ids[] = $label;
        }

        return $ids;
    }

    /*****************************************************************************
     * Definition Methods
     * =====================
     *
     * Methods used for defining a schema.
     ****************************************************************************/

    /**
     * Define schema label.
     *
     * @param string $label label name
     */
    public function label($label)
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Define table name.
     *
     * @param string $table table name
     */
    public function table($table)
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Invode helper.
     *
     * @param string $helperName
     * @param array  $arguments  indexed array, passed to the init function of helper class.
     *
     * @return Helper\BaseHelper
     */
    protected function helper($helperName, array $arguments = array())
    {
        $helperClass = "Maghead\\Schema\\Helper\\{$helperName}Helper";

        return new $helperClass($this, $arguments);
    }

    /**
     * Mixin.
     *
     * Availabel mixins
     *
     *     $this->mixin('Metadata' , array( options ) );
     *     $this->mixin('I18n');
     *
     * @param string $class mixin class name
     */
    public function mixin($class, array $options = array())
    {
        if (!class_exists($class, true)) {
            $class = Utils::resolveClass($class, ['Maghead\\Schema\\Mixin'], $this, ['Mixin']);
        }
        if (!class_exists($class)) {
            throw new Exception("Mixin class $class not found.");
        }

        $mixin = new $class($this, $options);
        $this->addMixinSchemaClass($class);
        $this->mixinSchemas[] = $mixin;

        /* merge columns into self */
        $this->columns = array_merge($this->columns, $mixin->columns);
        $this->relations = array_merge($this->relations, $mixin->relations);
        $this->indexes = array_merge($this->indexes, $mixin->indexes);
    }

    /**
     * set data source for both write and read.
     *
     * @param string $id data source id
     */
    public function using($id)
    {
        $this->writeSourceId = $id;
        $this->readSourceId = $id;

        return $this;
    }

    /**
     * set data source for write.
     *
     * @param string $id data source id
     */
    public function writeTo($id)
    {
        $this->writeSourceId = $id;

        return $this;
    }

    /**
     * set data source for read.
     *
     * @param string $id data source id
     */
    public function readFrom($id)
    {
        $this->readSourceId = $id;

        return $this;
    }

    /**
     * 'index' method helps you define index queries.
     *
     * @return CreateIndexQuery
     */
    public function index($name, $columns = null, $using = null)
    {
        // return the cached index query object
        if (isset($this->indexes[$name])) {
            return $this->indexes[$name];
        }
        $query = $this->indexes[$name] = new CreateIndexQuery($name);
        if ($columns) {
            if (!$columns || empty($columns)) {
                throw new InvalidArgumentException('index columns must not be empty.');
            }
            $query->on($this->getTable(), (array) $columns);
        }
        if ($using) {
            $query->using($using);
        }

        return $query;
    }


    /**
     * seeds method is used for user to define the pre-configured dataset in plain array structure.
     *
     * this could reduce the effort to check the errors.
     *
     * @return array
     */
    public function seeds()
    {
        return false;
    }


    /**
     * 'seeds' helps you define seed classes.
     *
     *     $this->seeds('User\\Seed','Data\\Seed');
     *
     * @return DeclareSchema
     */
    public function useSeeds()
    {
        $seeds = func_get_args();
        $self = $this;
        $this->seeds = array_map(function ($class) use ($self) {
            return Utils::resolveClass($class, [], $self, ['Seeds']);
        }, $seeds);
    }

    /**
     * Add seed class.
     *
     * @param string $seed
     */
    public function addSeed($class)
    {
        $this->seeds[] = Utils::resolveClass($class, [], $this, ['Seeds']);

        return $this;
    }

    public function globalTable($mappingId)
    {
        $this->globalTable = true;
        $this->shardMapping = $mappingId;

        return $this;
    }

    /**
     * Define the shard mapping ID used for sharding.
     *
     * @param string $mappingId
     */
    public function shardBy($mappingId)
    {
        $this->shardMapping = $mappingId;

        return $this;
    }

    /**
     * Return the current schema (for mixin schema, we need to return the parent schema object)
     */
    protected function getCurrentSchema()
    {
        return $this;
    }

    /*****************************************************************************
     * Relationship Definition Methods
     * ===============================
     *
     * Methods used for defining relationships
     ****************************************************************************/

    /**
     * define self primary key to foreign key reference.
     *
     * comments(
     *    post_id => author.comment_id
     * )
     *
     * $post->publisher
     *
     * @param string $foreignClass  foreign schema class.
     * @param string $foreignColumn foreign reference schema column.
     * @param string $selfColumn    self column name, the self column could be configured lately by using "by(...)"
     *
     * @return Relationship
     */
    public function belongsTo($accessor, $foreignClass, $foreignColumn = null, $selfColumn = null)
    {
        $foreignClass = $this->resolveSchemaClass($foreignClass);
        if (!$foreignColumn) {
            $schema = SchemaLoader::load($foreignClass);
            $foreignColumn = $schema->findPrimaryKey();
        }

        return $this->relations[$accessor] = new BelongsTo($accessor, [
            'foreign_schema' => $foreignClass,
            'foreign_column' => $foreignColumn,
            'self_schema'    => get_class($this->getCurrentSchema()),
            'self_column'    => $selfColumn,
        ]);
    }

    /**
     * has-one relationship.
     *
     *   model(
     *      post_id => post
     *   )
     *
     * @param string $accessor      accessor name.
     * @param string $foreignClass  foreign schema class
     * @param string $foreignColumn foreign schema column
     * @param string $selfColumn    self schema column
     */
    public function hasOne($accessor, $foreignClass, $foreignColumn = null, $selfColumn)
    {
        if (!$foreignColumn) {
            $schema = new $foreignClass;
            $foreignColumn = $schema->primaryKey;
        }

        return $this->relations[ $accessor ] = new HasOne($accessor, array(
            'self_schema' => get_class($this->getCurrentSchema()),
            'self_column' => $selfColumn,
            'foreign_schema' => $this->resolveSchemaClass($foreignClass),
            'foreign_column' => $foreignColumn,
        ));
    }

    /**
     * onUpdate defines a software trigger of update action.
     */
    public function onUpdate($action)
    {
        $this->onUpdate = $action;

        return $this;
    }

    /**
     * onDelete defines a software trigger of delete action.
     *
     * @param string $action Currently for 'cascade'
     */
    public function onDelete($action)
    {
        $this->onDelete = $action;

        return $this;
    }

    public function hasMany()
    {
        // forward call
        return call_user_func_array(array($this, 'many'), func_get_args());
    }

    /**
     * Add has-many relation.
     *
     * TODO: provide a relationship object to handle sush operation, that will be:
     *
     *    $this->hasMany('books','id')
     *         ->from(BookSchema::class,'author_id')
     *
     *
     * @param string $accessor      accessor name.
     * @param string $foreignClass  foreign schema class
     * @param string $foreignColumn foreign schema column
     * @param string $selfColumn    self schema column
     */
    public function many($accessor, $foreignClass, $foreignColumn, $selfColumn)
    {
        return $this->relations[$accessor] = new HasMany($accessor, array(
            'self_schema'    => get_class($this->getCurrentSchema()),
            'self_column'    => $selfColumn,
            'foreign_schema' => $this->resolveSchemaClass($foreignClass),
            'foreign_column' => $foreignColumn,
        ));
    }

    /**
     * @param string $accessor          accessor name.
     * @param string $relationId        a hasMany relationship.
     * @param string $foreignRelationId foreign relation id.
     */
    public function manyToMany($accessor, $relationId, $foreignRelationId)
    {
        if ($r = $this->getRelation($relationId)) {
            return $this->relations[ $accessor ] = new ManyToMany($accessor, array(
                'relation_junction' => $relationId,
                'relation_foreign' => $foreignRelationId,
            ));
        }
        throw new Exception("Relation $relationId is not defined.");
    }

    protected function resolveSchemaClass($class)
    {
        if (!preg_match('/Schema$/', $class)) {
            $class = "{$class}Schema";
        }
        $class2 = \Maghead\Utils::resolveClass($class, [], $this);
        if (!$class2) {
            throw new Exception("Schema class $class not found.");
        }

        return $class2;
    }

    /*****************************************************************************
     *                            File Level Methods
     ****************************************************************************/

    /**
     * Get the related class file path by the given class name.
     *
     * @param string $class the scheam related class name
     *
     * @code
     *   $schema->getRelatedClassPath( $schema->getModelClass() );
     *   $schema->getRelatedClassPath("App\\Model\\Book"); // return {app dir}/App/Model/Book.php
     * @code
     *
     * @return string the class filepath.
     */
    public function getRelatedClassPath($class)
    {
        $_p = explode('\\', $class);
        $shortClassName = end($_p);

        return $this->getDirectory().DIRECTORY_SEPARATOR.$shortClassName.'.php';
    }

    /**
     * Get directory from current schema object.
     *
     * @return string path
     */
    public function getDirectory()
    {
        return $dir = dirname($this->refl->getFilename());
    }

    public function getClassFileName()
    {
        return $this->refl->getFilename();
    }

    /**
     * Return the modification time of this schema definition class.
     *
     * @return int timestamp
     */
    public function getModificationTime()
    {
        return filemtime($this->refl->getFilename());
    }

    /**
     * isNewerThanFile returns true if the schema file is newer than a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function isNewerThanFile($path)
    {
        if (!file_exists($path)) {
            return true;
        }

        return $this->getModificationTime() > filemtime($path);
    }

    /**
     * requireProxyFileUpdate returns true if the schema proxy file is out of date.
     *
     * @return bool
     */
    public function requireProxyFileUpdate()
    {
        $classFilePath = $this->getRelatedClassPath($this->getSchemaProxyClass());

        return $this->isNewerThanFile($classFilePath);
    }

    /**
     * @return CreateIndexQuery[]
     */
    public function getIndexQueries()
    {
        return $this->indexes;
    }

    public function newSelectQuery()
    {
        $query = new SelectQuery();
        $query->from($this->getTable());
        $query->select('*');
        return $query;
    }

    public function newFindByGlobalPrimaryKeyQuery()
    {
        if ($globalPrimaryKey = $this->findGlobalPrimaryKey()) {
            $query = $this->newSelectQuery();
            $query->where()->equal($globalPrimaryKey, new ParamMarker($globalPrimaryKey));
            $query->limit(1);
            return $query;
        }
    }

    public function newFindByPrimaryKeyQuery()
    {
        $query = $this->newSelectQuery();
        $query->where()->equal($this->primaryKey, new ParamMarker($this->primaryKey));
        $query->limit(1);
        return $query;
    }

    public function newDeleteByPrimaryKeyQuery()
    {
        $query = new DeleteQuery();
        $query->delete($this->getTable());
        $query->where()->equal($this->primaryKey, new ParamMarker($this->primaryKey));
        return $query;
    }

    /**
     * @return string pgsql|mysql|sqlite
     */
    public function getPlatforms()
    {
        if ($comment = $this->refl->getDocComment()) {
            if (preg_match("/@platform\s+([\w\|]+)/i", $comment, $matches)) {
                return array_map('strtolower', explode('|', $matches[1]));
            }
        }
        return false;
    }

    public function hasPlatformSupport(BaseDriver $driver)
    {
        if ($platforms = $this->getPlatforms()) {
            return in_array($driver::ID, $platforms);
        }
        return true;
    }
}
