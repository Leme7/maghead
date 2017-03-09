<?php

namespace Maghead\Runtime;

use Exception;
use InvalidArgumentException;
use BadMethodCallException;
use PDO;
use PDOException;
use ArrayIterator;
use Serializable;
use ArrayAccess;
use SQLBuilder\Universal\Query\SelectQuery;
use SQLBuilder\Universal\Query\UpdateQuery;
use SQLBuilder\Universal\Query\DeleteQuery;
use SQLBuilder\Universal\Query\InsertQuery;
use SQLBuilder\Driver\PDOPgSQLDriver;
use SQLBuilder\Driver\PDOMySQLDriver;
use SQLBuilder\Bind;
use SQLBuilder\ArgumentArray;
use SQLBuilder\Raw;
use Maghead\Schema\SchemaLoader;
use Maghead\Schema\RuntimeColumn;
use Maghead\Schema\Relationship\Relationship;
use Maghead\ConfigLoader;
use Maghead\Exception\MissingPrimaryKeyException;
use Maghead\Exception\QueryException;
use Maghead\Manager\ConnectionManager;
use Maghead\Sharding\Manager\ShardManager;
use Maghead\Connection;
use SerializerKit\XmlSerializer;
use ActionKit;
use Symfony\Component\Yaml\Yaml;

defined('YAML_UTF8_ENCODING') || define('YAML_UTF8_ENCODING', 0);

/**
 * Base Model class,
 * every model class extends from this class.
 */
abstract class BaseModel implements Serializable
{
    use ActionCreatorTrait;
    use RepoFactoryTrait;

    public static $yamlExtension = false;

    public static $yamlEncoding = YAML_UTF8_ENCODING;

    public static $connectionManager;

    /**
     * @var array Mixin classes are emtpy. (MixinDeclareSchema)
     * */
    public static $mixin_classes = array();

    protected $_data = array();

    protected $_cache = array();

    protected $_foreignRecordCache = array();

    public $dataLabelField;

    public $dataValueField;

    // static $schemaCache;

    public $usingDataSource;

    protected $_schema;

    public static $_cacheInstance;


    /**
     * Get the RuntimeColumn objects from RuntimeSchema object.
     */
    public function columns()
    {
        return static::getSchema()->columns;
    }

    protected function getCachePrefix()
    {
        return static::SCHEMA_CLASS;
    }

    public function unsetPrimaryKey()
    {
        $this->setKey(null);
    }

    public function getDataLabelField()
    {
        return $this->dataLabelField ?: static::PRIMARY_KEY;
    }

    public function getDataValueField()
    {
        return $this->dataValueField ?: static::PRIMARY_KEY;
    }

    /**
     * This is for select widget,
     * returns label value from specific column.
     */
    public function dataLabel()
    {
        return $this->get($this->getDataLabelField());
    }

    /**
     * This is for select widget,
     * returns data key from specific column.
     */
    public function dataKeyValue()
    {
        return $this->get($this->getDataValueField());
    }

    /**
     * Alias method of $this->dataValue().
     */
    public function dataValue()
    {
        return $this->dataKeyValue();
    }

    /**
     * An alias for BaseRepo::loadByKeys
     */
    protected static function loadByKeys(array $args, $byKeys = null)
    {
        return static::masterRepo()->loadByKeys($args, $byKeys);
    }

    /**
     * Create or update an record by checking
     * the existence from the $byKeys array
     * that you defined.
     *
     * If the record exists, then the record should be updated.
     * If the record does not exist, then the record should be created.
     *
     * @param array $byKeys
     * @return Result
     */
    public function updateOrCreate(array $args)
    {
        if ($this->hasKey()) {
            return $this->update($args);
        }
        return static::masterRepo()->create($args);
    }


    /**
     * Create a record if the record does not exists
     * Otherwise the record should be updated with the arguments.
     *
     * @param array $args
     * @param array $byKeys it's optional if you defined primary key
     */
    public function loadOrCreate(array $args, $byKeys = null)
    {
        $repo = static::masterRepo();
        $record = $repo->loadByKeys($args, $byKeys);
        if ($record) {
            return $record;
        }
        $ret = $repo->create($args);
        if ($ret->error) {
            return false;
        }
        return $repo->loadByPrimaryKey($ret->key);
    }


    /**
     * Return the shards used by this model.
     *
     * @return Maghead\Sharding\Shard[]
     */
    public static function shards(ShardManager $shardManager = null)
    {
        if ($shardManager) {
            return $shardManager->getShardsOf(static::SHARD_MAPPING_ID);
        }
        // Get shard nodes of this table.
        $config = ConfigLoader::getCurrentConfig();
        $shardManager = new ShardManager($config, ConnectionManager::getInstance());
        return $shardManager->getShardsOf(static::SHARD_MAPPING_ID);
    }

    /**
     * create method
     *
     * @param array $args
     */
    public static function create(array $args)
    {
        if (static::GLOBAL_TABLE) {
            $results = [];
            $results['master'] = $ret = static::masterRepo()->create($args);

            // Update primary key
            if (!isset($args[$ret->keyName])) {
                $args[$ret->keyName] = $ret->key;
            }

            // TODO: Check error, log and retry,
            // TODO: MAP QUERY WORKER
            // support global transaction
            $shards = static::shards();
            foreach ($shards as $shardId => $shard) {
                $repo = $shard->createRepo(static::REPO_CLASS);
                $results[$shardId] = $repo->create($args);
            }
            return $ret;
        } else if (static::SHARD_MAPPING_ID) {
            // Generate an UUID

            // Select shards by the key




        } else {
            return static::masterRepo()->create($args);
        }
    }

    /**
     * Create and return the created record.
     */
    public static function createAndLoad(array $args)
    {
        $repo = static::masterRepo();
        $ret = $repo->create($args);
        if ($ret->error) {
            return false;
        }
        return $repo->loadByPrimaryKey($ret->key);
    }

    /**
     * find() is an alias method of masterRepo->find
     */
    public static function load($arg)
    {
        return static::masterRepo()->load($arg);
    }

    public static function loadByPrimaryKey($arg)
    {
        return static::masterRepo()->loadByPrimaryKey($arg);
    }

    public static function loadWith($args)
    {
        return static::masterRepo()->loadWith($args);
    }

    public static function loadForUpdate($args)
    {
        return static::masterRepo()->loadForUpdate($args);
    }

    /**
     * Delete current record, the record should be loaded already.
     *
     * @return Result operation result (success or error)
     */
    public function delete()
    {
        $key = $this->getKey();
        $repo = static::masterRepo();
        $repo->beforeDelete($this);
        $repo->deleteByPrimaryKey($key);
        $repo->afterDelete($this);
        return Result::success('Record deleted', [ 'type' => Result::TYPE_DELETE ]);
    }

    /**
     * Update current record.
     *
     * @param array $args
     *
     * @return Result operation result (success or error)
     */
    public function update(array $args)
    {
        // Check if we get primary key value
        // here we allow users to specifty primary key value from arguments if the record is not loaded.
        $key = $this->getKey();
        if (!$key) {
            return Result::failure('Record is not loaded, Can not update record.', array('args' => $args));
        }
        $ret = static::masterRepo()->updateByPrimaryKey($key, $args);
        $this->setData($args);
        return $ret;
    }

    /**
     * Simply update record without validation and triggers.
     *
     * @param array $args
     */
    public function rawUpdate(array $args)
    {
        $key = $this->getKey();
        if (!$key) {
            return Result::failure('Record is not loaded, Can not update record.', array('args' => $args));
        }
        $ret = static::masterRepo()->rawUpdateByPrimaryKey($key, $args);
        $this->setData($args);
        return $ret;
    }

    /**
     * Simply create record without validation and triggers.
     *
     * @param array $args
     */
    public static function rawCreate(array $args)
    {
        return static::masterRepo()->rawCreate($args);
    }

    /**
     * Save current data (create or update)
     * if primary key is defined, do update
     * if primary key is not defined, do create.
     *
     * @return Result operation result (success or error)
     */
    public function save()
    {
        $key = $this->getKey();
        $data = $this->getData();
        if ($key) {
            return static::masterRepo()->updateByPrimaryKey($key, $data);
        }
        return static::masterRepo()->create($data);
    }


    // ============================ MIXIN METHODS ===========================

    /**
     * Find methods in mixin schema classes, methods will be called statically.
     *
     * @param string $m method name
     *
     * @return string the mixin class name.
     */
    public static function findMixinMethodClass($m)
    {
        foreach (static::$mixin_classes as $mixinClass) {
            // if we found it, just call it and return the result.
            if (method_exists($mixinClass, $m)) {
                return $mixinClass;
            }
        }

        return false;
    }


    /**
     * Invoke single mixin class method statically,.
     *
     * @param string $mixinClass mixin class name.
     * @param string $m          method name.
     * @parma array  $a method arguments.
     *
     * @return mixed execution result
     */
    protected function invokeMixinClassMethod($mixinClass, $m, array $a)
    {
        return call_user_func_array(array($mixinClass, $m), array_merge(array($this), $a));
    }


    /**
     * Invoke method on all mixin classes statically. this method does not return anything.
     *
     * @param string $m method name.
     * @param array  $a method arguments.
     */
    protected function invokeAllMixinMethods($m, $a)
    {
        foreach (static::$mixin_classes as $mixinClass) {
            // If we found it, just call it and return the result.
            if (method_exists($mixinClass, $m)) {
                call_user_func_array(array($mixinClass, $m), array_merge(array($this), $a));
            }
        }
    }

    public static function __callStatic($method, $args)
    {
        $repo = static::masterRepo();
        return call_user_func_array([$repo, $method], $args);
    }

    /**
     * __call method is slower than normal method, because there are
     * one more method table to look up. you should call methods directly
     * if you need better performance.
     */
    public function __call($m, $a)
    {
        // Dispatch to schema object method first
        $schema = static::getSchema();
        if (method_exists($schema, $m)) {
            return call_user_func_array(array($schema, $m), $a);
        }

        if ($mClass = static::findMixinMethodClass($m)) {
            return $this->invokeMixinClassMethod($mClass, $m, $a);
        }

        // special case for twig template
        throw new BadMethodCallException(get_class($this).": $m method not found.");
    }


    /**
     * Render readable column value.
     *
     * @param string $name column name
     */
    public function display($name)
    {
        if ($c = static::getSchema()->getColumn($name)) {
            // get raw value
            if ($c->virtual) {
                return $this->get($name);
            }
            return $c->display($this->getValue($name));
        } elseif (isset($this->$name)) {
            return $this->$name;
        }

        // for relationship record
        $val = $this->get($name);
        if ($val && $val instanceof \Maghead\Runtime\BaseModel) {
            return $val->dataLabel();
        }
    }


    /**
     * get pdo connetion and make a query.
     *
     * @param string $sql SQL statement
     *
     * @return PDOStatement pdo statement object.
     *
     *     $stm = $this->dbQuery($sql);
     *     foreach( $stm as $row ) {
     *              $row['name'];
     *     }
     */
    public function dbQuery($dsId, $sql)
    {
        $conn = static::$connectionManager->getConnection($dsId);
        if (!$conn) {
            throw new Exception("data source $dsId is not defined.");
        }

        return $conn->query($sql);
    }

    /**
     * Load record from an sql query.
     *
     * @param string $sql  sql statement
     * @param array  $args
     * @param string $dsId data source id
     *
     *     $result = $record->loadQuery( 'select * from ....', array( ... ) , 'master' );
     *
     * @return Result
     */
    public function loadQuery($sql, array $args = array(), $dsId = null)
    {
        if (!$dsId) {
            $dsId = $this->readSourceId;
        }

        $conn = static::$connectionManager->getConnection($dsId);
        $stm = $conn->prepare($sql);
        $stm->setFetchMode(PDO::FETCH_CLASS, get_class($this));
        $stm->execute($args);
        if (false === ($data = $stm->fetch(PDO::FETCH_CLASS))) {
            return Result::failure('Data load failed.', array(
                'sql'  => $sql,
                'args' => $args,
            ));
        }
        $this->setData($data);
        return Result::success('Data loaded', array(
            'key'  => $this->getKey(),
            'sql' => $sql,
        ));
    }

    /**
     * We should move this method into connection manager.
     *
     * @return PDOStatement
     */
    public function dbPrepareAndExecute(PDO $conn, $sql, array $args = array())
    {
        $stm = $conn->prepare($sql);
        $stm->execute($args);

        return $stm;
    }

    public function getSchemaProxyClass()
    {
        return static::SCHEMA_PROXY_CLASS;
    }

    /**
     * Get inflate value.
     *
     * @param string $name Column name
     */
    public function get($key)
    {
        // relationship id can override value column.
        if ($relation = static::getSchema()->getRelation($key)) {
            // use model query to load relational record.
            return $this->getRelationalRecords($key, $relation);
        }
        return $this->inflateColumnValue($key);
    }

    /**
     * Check if the value exist.
     *
     * @param string $name
     *
     * @return bool
     */
    public function hasValue($name)
    {
        return isset($this->$name);
    }

    /**
     * Get the raw value from record (without deflator).
     *
     * @param string $name
     *
     * @return mixed
     */
    public function getValue($name)
    {
        return $this->$name;
    }

    /**
     * This will be overrided by child model class.
     */
    abstract public function getData();

    /**
     * This will be overrided by child model class.
     */
    abstract public function setData(array $data);

    /**
     * Do we have this column ?
     *
     * @param string $name
     */
    public function __isset($name)
    {
        return property_exists($this, $name)
            || isset(static::getSchema()->columns[$name])
            || $this->getSchema()->getRelation($name)
            ;
    }

    /**
     * __get magic method is used for getting:
     *
     * 1. virtual column data
     * 2. relational records
     *
     * @param string $key
     */
    public function __get($key)
    {
        return $this->get($key);
    }

    /**
     * Dynamically create a model object with the relationship key for HAS-ONE relationship.
     *
     * @param string $key
     * @return \Maghead\Runtime\BaseModel
     */
    protected function fetchHasOne($key)
    {
        $cacheKey = 'relationship::'.$key;
        $relation = static::getSchema()->getRelation($key);
        $selfColumn = $relation['self_column'];
        $fSchema = $relation->newForeignSchema();
        $fColumn = $relation['foreign_column'];
        if (!$this->$selfColumn) {
            return;
        }
        $sValue = $this->$selfColumn;
        $model = $relation->newForeignModel();
        $record = $model::loadWith(array($fColumn => $sValue));
        $this->setInternalCache($cacheKey, $record);
        return $record;
    }

    /**
     * Dynamically create a model object with the relationship key for BELONGS-TO relationship.
     *
     * @param string $key
     * @return \Maghead\Runtime\BaseModel
     */
    protected function fetchBelongsTo($key)
    {
        $cacheKey = 'relationship::'.$key;
        $relation = static::getSchema()->getRelation($key);
        $selfColumn = $relation['self_column'];
        $foreignSchema = $relation->newForeignSchema();
        $foreignColumn = $relation['foreign_column'];
        if (!isset($this->$selfColumn)) {
            return;
        }
        $sValue = $this->$selfColumn;
        $model = $foreignSchema->newModel();
        $record = $model::loadWith([$foreignColumn => $sValue]);
        $this->setInternalCache($cacheKey, $record);
        return $record;
    }

    protected function fetchHasMany($key)
    {
        $cacheKey = 'relationship::'.$key;
        $relation = static::getSchema()->getRelation($key);

        // TODO: migrate this code to Relationship class.
        $selfColumn = $relation['self_column'];
        $fSchema = $relation->newForeignSchema();

        $fColumn = $relation['foreign_column'];

        if (!isset($this->$selfColumn)) {
            return;
        }

        $sValue = $this->$selfColumn;

        $collection = $relation->getForeignCollection();
        $collection->where()->equal($collection->getAlias().'.'.$fColumn, $sValue); // where 'm' is the default alias.

        // For if we need to create relational records
        // though collection object, we need to pre-set
        // the relational record id.
        $collection->setPresetVars([$fColumn => $sValue]);
        $this->setInternalCache($cacheKey, $collection);
        return $collection;
    }


    public function getRelationalRecords($key, $relation = null)
    {
        // check for the object cache
        $cacheKey = 'relationship::'.$key;
        if (!$relation) {
            $relation = static::getSchema()->getRelation($key);
        }

        switch ($relation['type']) {
            case Relationship::HAS_ONE:
                return $this->fetchHasOne($key);
                break;
            case Relationship::BELONGS_TO:
                return $this->fetchBelongsTo($key);
                break;
            case Relationship::HAS_MANY:
                return $this->fetchHasMany($key);
                break;
        }

        if (Relationship::MANY_TO_MANY === $relation['type']) {
            $junctionRelKey = $relation['relation_junction'];  // use relationId to get middle relation. (author_books)
            $rId2 = $relation['relation_foreign'];  // get external relationId from the middle relation. (book from author_books)

            $junctionRel = static::getSchema()->getRelation($junctionRelKey);
            if (!$junctionRel) {
                throw new InvalidArgumentException("first level relationship of many-to-many $junctionRelKey is empty");
            }

            // eg. author_books
            $sColumn = $junctionRel['foreign_column'];
            $sSchema = $junctionRel->newForeignSchema();

            $foreignRel = $sSchema->getRelation($rId2);
            if (!$foreignRel) {
                throw new InvalidArgumentException("second level relationship of many-to-many $rId2 is empty.");
            }

            $fSchema = $foreignRel->newForeignSchema();
            $fColumn = $foreignRel['foreign_column'];
            $collection = $fSchema->newCollection();

            // join middle relation ship
            //
            //    SELECT * from books b left join author_books ab on ( ab.book_id = b.id )
            //       WHERE b.author_id = :author_id
            $collection->join($sSchema->getTable())->as('b')
                            ->on()
                            ->equal('b.'.$foreignRel['self_column'], array($collection->getAlias().'.'.$fColumn));

            $value = $this->getValue($junctionRel['self_column']);
            $collection->where()->equal('b.'.$junctionRel['foreign_column'], $value);

            // for creating many-to-many subrecords, like:
            //
            //    $author->books[] = array(
            //        'author_books' => [ 'created_on' => date('c') ],
            //        'title' => 'Book Title',
            //    );
            $collection->setAfterCreate(function ($record, $args) use ($sSchema, $junctionRelKey, $junctionRel, $foreignRel, $value) {
                // arguments for creating middle-relationship record
                $a = [
                    $foreignRel['self_column'] => $record->getValue($foreignRel['foreign_column']),  // 2nd relation model id
                    $junctionRel['foreign_column'] => $value,  // self id
                ];

                if (isset($args[$junctionRelKey])) {
                    $a = array_merge($args[$junctionRelKey], $a);
                }

                // create relationship
                $middleRecord = $sSchema->newModel();
                $ret = $middleRecord::create($a);
                if ($ret->error) {
                    throw new Exception("$junctionRelKey record create failed.");
                }
                return $middleRecord::loadByPrimaryKey($ret->key);
            });
            $this->setInternalCache($cacheKey, $collection);
            return $collection;
        }

        throw new Exception("The relationship type of $key is not supported.");
    }


    /**
     * Return the collection object of current model object.
     *
     * @return Maghead\Runtime\BaseCollection
     */
    public function asCollection()
    {
        $class = static::COLLECTION_CLASS;
        return $class::fromArray([$this]);
    }

    /**
     * return data stash array,.
     *
     * @return array
     */
    public function toArray(array $fields = null)
    {
        $data = $this->getData();
        if ($fields) {
            return array_intersect_key($data, array_flip($fields));
        }
        return $data;
    }

    /**
     * Deflate data and return.
     *
     * @return array
     */
    public function toInflatedArray()
    {
        $data = array();
        $schema = static::getSchema();
        $data = $this->getData();
        foreach ($data as $k => $v) {
            $col = $schema->getColumn($k);
            if ($col && $col->isa) {
                $data[ $k ] = $col->inflate($v, $this);
            } else {
                $data[ $k ] = $v;
            }
        }

        return $data;
    }

    /**
     * return json format data.
     *
     * @return string JSON string
     */
    public function toJson()
    {
        $data = $this->getData();
        return json_encode($data);
    }

    /**
     * Return xml format data.
     *
     * @return string XML string
     */
    public function toXml()
    {
        // TODO: improve element attributes
        $ser = new XmlSerializer();
        $data = $this->getData();
        return $ser->encode($data);
    }

    /**
     * Return YAML format data.
     *
     * @return string YAML string
     */
    public function toYaml()
    {
        $data = $this->getData();
        if (self::$yamlExtension) {
            return yaml_emit($data, YAML_UTF8_ENCODING);
        }
        return file_put_contents($yamlFile, "---\n".Yaml::dump($data, $inline = true, $exceptionOnInvalidType = true));
    }


    /**
     * Inflate column value.
     *
     * @param string $n Column name
     *
     * @return mixed
     */
    protected function inflateColumnValue($n)
    {
        $value = property_exists($this, $n) ? $this->$n : null;
        if ($c = static::getSchema()->getColumn($n)) {
            return $c->inflate($value, $this);
        }
        return $value;
    }

    public function getDeclareSchema()
    {
        $class = static::SCHEMA_CLASS;

        return new $class();
    }

    public static function getSchema()
    {
        // This is not static property becase different model loads different schema objects.
        if ($this->_schema) {
            return $this->_schema;
        } elseif (constant('static::SCHEMA_PROXY_CLASS')) {
            if ($this->_schema = SchemaLoader::load(static::SCHEMA_PROXY_CLASS)) {
                return $this->_schema;
            }
            throw new Exception('Can not load '.static::SCHEMA_PROXY_CLASS);
        }
        throw new Exception('schema is not defined in '.get_class($this));
    }

    /***************************************
     * Cache related methods
     ***************************************/

    /**
     * flush internal cache, in php memory.
     */
    public function flushInternalCache()
    {
        $this->_cache = array();
    }

    /**
     * set internal cache, in php memory.
     *
     * @param string $key cache key
     * @param mixed  $val cache value
     *
     * @return mixed cached value
     */
    protected function setInternalCache($key, $val)
    {
        $this->_cache[ $key ] = $val;
    }

    /**
     * get internal cache from php memory.
     *
     * @param string $key cache key
     *
     * @return mixed cached value
     */
    public function getInternalCache($key)
    {
        if (isset($this->_cache[ $key ])) {
            return $this->_cache[ $key ];
        }
    }

    public function hasInternalCache($key)
    {
        return isset($this->_cache[ $key ]);
    }

    public function clearInternalCache()
    {
        $this->_cache = array();
    }


    /**
     * Used by ActionKit
     */
    public function fetchOneToManyRelationCollection($relationId)
    {
        if ($this->id && isset($this->{ $relationId })) {
            return $this->{$relationId};
        }
    }

    /**
     * Used by ActionKit
     */
    public function fetchManyToManyRelationCollection($relationId)
    {
        $schema = static::getSchema();
        $relation = $schema->getRelation($relationId);
        return $relation->newForeignForeignCollection(
            $schema->getRelation($relation['relation_junction'])
        );
    }


    // Serializable interface methods
    // ===============================
    public function serialize()
    {
        return serialize($this->getData());
    }

    public function unserialize($str)
    {
        $this->setData(unserialize($str));
    }

    /**
     * Create a record object from array.
     */
    public static function fromArray(array $array)
    {
        $record = new static;
        $record->setData($array);
        return $record;
    }
}
