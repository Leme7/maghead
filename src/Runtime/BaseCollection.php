<?php

namespace Maghead\Runtime;

use PDO;
use PDOStatement;
use RuntimeException;
use Exception;
use ArrayAccess;
use Countable;
use IteratorAggregate;
use ArrayIterator;
use SQLBuilder\Universal\Query\SelectQuery;
use SQLBuilder\Universal\Query\UpdateQuery;
use SQLBuilder\Universal\Query\DeleteQuery;
use SQLBuilder\Universal\Traits\WhereTrait;
use SQLBuilder\Driver\BaseDriver;
use SQLBuilder\ArgumentArray;
use SerializerKit\XmlSerializer;
use Symfony\Component\Yaml\Yaml;

use Maghead\Schema\SchemaLoader;
use Maghead\Schema\BaseSchema;
use Maghead\Manager\DataSourceManager;

defined('YAML_UTF8_ENCODING') || define('YAML_UTF8_ENCODING', 0);

/**
 * base collection class.
 */
class BaseCollection implements
    ArrayAccess,
    Countable,
    IteratorAggregate
{
    use RepoFactoryTrait;
    use WhereTrait;

    public static $yamlExtension;

    public static $jsonOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

    public static $dataSourceManager;

    protected $_lastSql;

    protected $_vars;

    protected $_query;

    /**
     * @var PDOStatement handle
     */
    protected $handle;

    /**
     * handle data for items.
     *
     * @var array
     */
    protected $_rows = null;

    /**
     * preset vars for creating.
     */
    protected $_presetVars = array();

    /**
     * postCreate hook.
     */
    protected $_postCreate;

    protected $_schema;

    /**
     * operation result object.
     */
    protected $_result;

    protected $_alias = 'm';

    protected $explictSelect = false;

    protected $preferredTable;

    public $selected;


    /**
     * $this->defaultOrdering = array(
     *    array( 'id', 'desc' ),
     *    array( 'name', 'desc' ),
     * );.
     */
    protected $defaultOrdering = array();

    protected $repo;

    /**
     * Basically we won't create mass collection objects in one time.
     * Therefore we can prepare more stuff here.
     */
    public function __construct(BaseRepo $repo = null, PDOStatement $stm = null)
    {
        $this->repo = $repo;
        $this->handle = $stm;
    }

    public function setRepo(BaseRepo $repo)
    {
        $this->repo = $repo;
    }


    /**
     * TODO: The iterator method here could be replaced with Generator iterator by inherits different class or Trait.
     */
    public function getIterator()
    {
        if (!$this->_rows) {
            $this->_rows = $this->readRows();
        }
        return new ArrayIterator($this->_rows);
    }

    /**
     * This method will be overrided by code gen.
     */
    public static function getSchema()
    {
        if ($this->_schema) {
            return $this->_schema;
        } elseif (@constant('static::SCHEMA_PROXY_CLASS')) {
            return $this->_schema = SchemaLoader::load(static::SCHEMA_PROXY_CLASS);
        }
        throw new RuntimeException('schema is not defined in '.get_class($this));
    }

    public function getCurrentQuery()
    {
        return $this->_query ? $this->_query : $this->_query = $this->createReadQuery();
    }

    public function getRows()
    {
        if ($this->_rows) {
            return $this->_rows;
        }
        return $this->_rows = $this->readRows();
    }

    /**
     * Free cached row data and result handle,
     * But still keep the same query.
     *
     * @return $this
     */
    public function free()
    {
        $this->_rows = null;
        $this->_result = null;
        $this->handle = null;

        return $this;
    }

    /**
     * Dispatch undefined methods to SelectQuery object,
     * To achieve mixin-like feature.
     */
    public function __call($m, $a)
    {
        $q = $this->getCurrentQuery();
        if (method_exists($q, $m)) {
            return call_user_func_array(array($q, $m), $a);
        }
        throw new Exception("Undefined method $m");
    }

    public function getAlias()
    {
        return $this->_alias;
    }

    public function setAlias($alias)
    {
        $this->_alias = $alias;

        return $this;
    }

    public function setExplictSelect($boolean = true)
    {
        $this->explictSelect = $boolean;

        return $this;
    }

    public function select($sels)
    {
        $this->explictSelect = true;
        $this->selected = (array) $sels;

        return $this;
    }

    public function selectAll()
    {
        $dsId = static::getSchema()->getReadSourceId();
        $driver = $this->getQueryDriver($dsId);
        $this->explictSelect = true;
        $this->selected = $this->getExplicitColumnSelect($driver);
        return $this;
    }

    public function getSelected()
    {
        if ($this->selected) {
            return $this->selected;
        }
    }

    public function getTable()
    {
        if ($this->preferredTable) {
            return $this->preferredTable;
        }
        return static::TABLE;
    }

    public function setPreferredTable($tableName)
    {
        $this->preferredTable = $tableName;
    }

    protected function getCurrentRepo()
    {
        if ($this->repo) {
            return $this->repo;
        }
        return static::masterRepo(); // my repo
    }


    /**
     * Create a SelectQuery bases on the collection definition.
     *
     * @return SelectQuery
     */
    public static function asQuery()
    {
        $q = new SelectQuery();
        $q->from(static::TABLE, 'm'); // main table alias
        $q->select('*'); // default selection
        return $q;
    }

    /**
     * Create a SelectQuery bases on the collection definition.
     *
     * @return SelectQuery
     */
    public function createReadQuery()
    {
        $q = new SelectQuery();
        $q->from($this->getTable(), $this->_alias); // main table alias

        if ($selection = $this->getSelected()) {
            $q->select($selection);
        } else {
            // Need the driver instance to quote the field names
            $repo = $this->getCurrentRepo();
            $conn = $repo->getReadConnection();
            $driver = $conn->getQueryDriver();

            $q->select($this->explictSelect
                ? $this->getExplicitColumnSelect($driver)
                : $this->_alias.'.*');
        }

        // Setup Default Ordering.
        if (!empty($this->defaultOrdering)) {
            foreach ($this->defaultOrdering as $ordering) {
                $q->orderBy($ordering[0], $ordering[1]);
            }
        }

        return $q;
    }

    // xxx: this might be used in other join statements.
    public function getExplicitColumnSelect(BaseDriver $driver)
    {
        $alias = $this->_alias;

        return array_map(function ($name) use ($alias, $driver) {
            return $alias.'.'.$driver->quoteIdentifier($name);
        }, static::getSchema()->getColumnNames());
    }

    /**
     * prepare data handle, call fetch method to read data from database, and
     * catch the handle.
     *
     * Which calls doFetch() to do a query operation.
     */
    public function prepareHandle($force = false)
    {
        if (!$this->handle || $force) {
            $this->_result = $this->fetch();
        }

        return $this->handle;
    }

    /**
     * Build sql and Fetch from current query, make a query to database.
     *
     * @return OperationResult
     */
    public function fetch()
    {
        $repo = $this->getCurrentRepo();
        $conn = $repo->getReadConnection();
        $driver = $conn->getQueryDriver();

        $query = $this->getCurrentQuery();
        if ($this->where) {
            $query->setWhere($this->where);
        }

        $arguments = new ArgumentArray();
        $this->_lastSql = $sql = $this->getCurrentQuery()->toSql($driver, $arguments);
        $this->_vars = $vars = $arguments->toArray();
        $this->handle = $conn->prepareAndExecute($sql, $vars);
        return Result::success('Updated', array('sql' => $sql));
    }

    public function sql()
    {
        $repo = $this->getCurrentRepo();
        $conn = $repo->getReadConnection();
        $driver = $conn->getQueryDriver();

        $arguments = new ArgumentArray();
        $sql = $this->getCurrentQuery()->toSql($driver, $arguments);
        $args = $arguments->toArray();
        return [$sql, $args];
    }


    /**
     * Get current selected item size
     * by using php function `count`.
     *
     * @return int size
     */
    public function size()
    {
        if ($this->_rows) {
            return count($this->_rows);
        }
        $this->_rows = $this->readRows();
        return count($this->_rows);
    }

    public function distinct($field)
    {
        $repo = $this->getCurrentRepo();
        $conn = $repo->getReadConnection();
        $driver = $conn->getQueryDriver();

        $q = clone $this->getCurrentQuery();
        $q->setSelect("DISTINCT $field"); // Override current select.

        $arguments = new ArgumentArray();
        $sql = $q->toSql($driver, $arguments);

        $stm = $conn->prepare($sql);
        $stm->execute($arguments->toArray());
        return $stm->fetchAll(PDO::FETCH_COLUMN, 0);
    }


    /**
     * Clone current read query and apply select to count(*)
     * So that we can use the same conditions to query item count.
     *
     * This method implements the Countable interface.
     *
     * @return int
     */
    public function count()
    {
        $repo = $this->getCurrentRepo();
        $conn = $repo->getReadConnection();
        $driver = $conn->getQueryDriver();

        $q = clone $this->getCurrentQuery();
        $q->setSelect('COUNT(distinct m.id)'); // Override current select.
        if ($this->where) {
            $q->setWhere($this->where);
        }

        // When selecting count(*), we don't care group by and order by
        $q->removeOrderBy();
        $q->removeGroupBy();

        $arguments = new ArgumentArray();
        $sql = $q->toSql($driver, $arguments);

        $stm = $conn->prepare($sql);
        $stm->execute($arguments->toArray());
        return (int) $stm->fetchColumn();
    }

    /**
     * Query Limit for QueryBuilder.
     *
     * @param int $number
     */
    public function limit($number)
    {
        $this->getCurrentQuery()->limit($number);

        return $this;
    }

    /**
     * Query offset for QueryBuilder.
     *
     * @param int $number
     */
    public function offset($number)
    {
        $this->getCurrentQuery()->offset($number);

        return $this;
    }

    /**
     * A Short helper method for using limit and offset of QueryBuilder.
     *
     * @param int $page
     * @param int $pageSize
     *
     * @return $this
     */
    public function page($page, $pageSize = 20)
    {
        $this->limit($pageSize);
        $this->offset(
            ($page - 1) * $pageSize
        );

        return $this;
    }

    /**
     * Get selected items and wrap it into a CollectionPager object.
     *
     * CollectionPager is a simple data pager, do not depends on database.
     *
     * @return Maghead\CollectionPager
     */
    public function pager($page = 1, $pageSize = 10)
    {
        if (!$this->_rows) {
            $this->rows = $this->readRows();
        }
        // Setup limit
        return new CollectionPager($this->_rows, $page, $pageSize);
    }

    /**
     * Get items.
     *
     * @return Maghead\Runtime\BaseModel[]
     */
    public function items()
    {
        if (!$this->_rows) {
            $this->_rows = $this->readRows();
        }
        return $this->_rows;
    }

    public function fetchRow()
    {
        if (!$this->handle) {
            $this->prepareHandle();
        }

        return $this->handle->fetchObject(static::MODEL_CLASS);
    }

    /**
     * Read rows from database handle.
     *
     * @return model_class[]
     */
    protected function readRows()
    {
        // initialize the connection handle object
        if (!$this->handle) {
            $this->prepareHandle();
        }

        if (!$this->handle) {
            if ($this->_result->exception) {
                throw $this->_result->exception;
            }
            throw new RuntimeException(get_class($this).':'.$this->_result->message);
        }
        // Use fetch all
        return $this->handle->fetchAll(PDO::FETCH_CLASS, static::MODEL_CLASS);
    }

    public function delete()
    {
        $schema = static::getSchema();
        $repo = $this->getCurrentRepo();
        $conn = $repo->getWriteConnection();
        $driver = $conn->getQueryDriver();

        $query = new DeleteQuery();
        $query->from($this->getTable());
        if ($this->where) {
            $query->setWhere($this->where);
        }

        $arguments = new ArgumentArray();
        $sql = $query->toSql($driver, $arguments);

        try {
            $this->handle = $conn->prepareAndExecute($sql, $arguments->toArray());
        } catch (Exception $e) {
            return Result::failure('Collection delete failed: '.$e->getMessage(), array(
                'vars' => $arguments->toArray(),
                'sql' => $sql,
                'exception' => $e,
            ));
        }

        return Result::success('Deleted', array('sql' => $sql));
    }

    /**
     * Update collection.
     *
     * FIXME
     */
    public function update(array $data)
    {
        $schema = static::getSchema();

        $repo = $this->getCurrentRepo();
        $conn = $repo->getWriteConnection();
        $driver = $conn->getQueryDriver();

        $query = new UpdateQuery();
        if ($this->where) {
            $query->setWhere($this->where);
        }
        $query->update($this->getTable());
        $query->set($data);

        $arguments = new ArgumentArray();
        $sql = $query->toSql($driver, $arguments);

        try {
            $this->handle = $conn->prepareAndExecute($sql, $arguments->toArray());
        } catch (Exception $e) {
            return Result::failure('Collection update failed: '.$e->getMessage(), array(
                'vars' => $arguments->toArray(),
                'sql' => $sql,
                'exception' => $e,
            ));
        }

        return Result::success('Updated', array('sql' => $sql));
    }

    public function splice($pos, $count = null)
    {
        if (!$this->_rows) {
            $this->_rows = $this->readRows();
        }
        return array_splice($this->_rows, $pos, $count);
    }

    public function first()
    {
        if (!$this->_rows) {
            $this->_rows = $this->readRows();
        }

        return !empty($this->_rows) ? $this->_rows[0] : null;
    }

    public function last()
    {
        if (!$this->_rows) {
            $this->_rows = $this->readRows();
        }
        return end($this->_rows);
    }

    public function createAndAppend(array $args)
    {
        $record = $this->create($args);
        if ($record) {
            return $this->_rows[] = $record;
        }
        return false;
    }


    /** array access interface */
    public function offsetSet($name, $value)
    {
        if (!$this->_rows) {
            $this->_rows = $this->readRows();
        }
        if (null === $name) {
            // create from array
            if (is_array($value)) {
                $value = $this->create($value);
            }
        }
        return $this->_rows[ $name ] = $value;
    }

    public function offsetExists($name)
    {
        if (!$this->_rows) {
            $this->_rows = $this->readRows();
        }

        return isset($this->_rows[ $name ]);
    }

    public function offsetGet($name)
    {
        if (!$this->_rows) {
            $this->_rows = $this->readRows();
        }
        if (isset($this->_rows[ $name ])) {
            return $this->_rows[ $name ];
        }
    }

    public function offsetUnset($name)
    {
        if (!$this->_rows) {
            $this->_rows = $this->readRows();
        }
        unset($this->_rows[$name]);
    }

    public function each(callable $cb)
    {
        if (!$this->_rows) {
            $this->_rows = $this->readRows();
        }

        $collection = new static();
        $collection->setRecords(
            array_map($cb, $this->_rows)
        );

        return $collection;
    }

    public function filter(callable $cb)
    {
        if (!$this->_rows) {
            $this->_rows = $this->readRows();
        }

        $collection = new static();
        $collection->setRecords(array_filter($this->_rows, $cb));

        return $collection;
    }

    /**
     * Create a new model object.
     *
     * @return object BaseModel
     */
    public function newModel()
    {
        return static::getSchema()->newModel();
    }

    /**
     * Create a collection object from an data array.
     */
    public static function fromArray(array $list)
    {
        $collection = new static();
        $schema = static::getSchema();
        $records = [];
        foreach ($list as $item) {
            if ($item instanceof BaseModel) {
                $records[] = $item;
            } else {
                $model = $schema->newModel();
                $model->setData($item);
                $records[] = $model;
            }
        }
        $collection->setRecords($records);
        return $collection;
    }

    public function toArray()
    {
        return array_map(function ($item) {
            return $item->toArray();
        }, $this->items());
    }

    public function toInflatedArray()
    {
        return array_map(function ($item) {
            return $item->toInflatedArray();
        }, $this->items());
    }

    public function toXml()
    {
        $list = $this->toArray();
        $xml = new XmlSerializer();

        return $xml->encode($list);
    }

    public function toJson()
    {
        $list = $this->toArray();

        return json_encode($list, self::$jsonOptions);
    }

    public function toYaml()
    {
        $list = $this->toArray();
        self::$yamlExtension = extension_loaded('yaml');
        if (self::$yamlExtension) {
            return yaml_emit($list, YAML_UTF8_ENCODING);
        }

        return file_put_contents($yamlFile, "---\n".Yaml::dump($list, $inline = true, $exceptionOnInvalidType = true));
    }

    /**
     * Create new record or relationship record,
     * and append the record into _rows list.
     *
     * @param array $args Arguments for creating record
     *
     * @return mixed record object
     */
    public function create(array $args)
    {
        if ($this->_presetVars) {
            $args = array_merge($this->_presetVars, $args);
        }

        $record = $this->getSchema()->newModel();
        $record = $record->createAndLoad($args);
        if ($record) {
            if ($this->_postCreate) {
                $middleRecord = call_user_func($this->_postCreate, $record, $args);
                // $this->_postCreate($record,$args);
            }
            $this->_rows[] = $record;
            return $record;
        }
        return false;
    }

    public function setAfterCreate(callable $cb)
    {
        $this->_postCreate = $cb;
    }

    public function setPresetVars(array $vars)
    {
        $this->_presetVars = $vars;
    }

    public function getSql()
    {
        return $this->_lastSql;
    }

    public function getLastSql()
    {
        return $this->_lastSql;
    }

    public function getVars()
    {
        return $this->_vars;
    }

    public function getResult()
    {
        return $this->_result;
    }

    /**
     * Convert query to plain sql.
     */
    public function toSql()
    {
        $repo = $this->getCurrentRepo();
        $conn = $repo->getReadConnection();
        $driver = $conn->getQueryDriver();

        $query = $this->getCurrentQuery();
        $arguments = new ArgumentArray();
        $sql = $query->toSql($driver, $arguments);

        /*
         * FIXME:
        foreach($arguments as $name => $value) {
            $sql = preg_replace( "/$name\b/", $value, $sql );
        }
        */
        return $sql;
    }

    /**
     * Override QueryBuilder->join method,
     * to enable explict selection.
     *
     * For model/collection objects, we should convert it to table name
     *
     * Usage:
     *
     *       $collection->join(new Author, 'LEFT', 'a' ); // left join with alias 'a'
     *       $collection->join('authors'); // left join without alias
     *
     *       $collection->join( new Author, 'LEFT' , 'a' )
     *                  ->on('m.author_id', array('a.id') ); // LEFT JOIN authors table on m.author_id = a.id
     *
     *       $collection->join('authors','RIGHT','a'); // right join with alias 'a'
     *
     * @param mixed  $target (Model object or table name)
     * @param string $type   Join Type (default 'LEFT')
     * @param string $alias  Alias
     *
     * @return QueryBuilder
     */
    public function join($target, $type = 'LEFT', $alias = null, $relationId = null)
    {
        $this->explictSelect = true;

        // for models and schemas join
        if ($target instanceof BaseModel || $target instanceof BaseSchema) {
            $query = $this->getCurrentQuery();
            $table = $target->getTable();

            /* XXX: should get selected column names by default, if not get all column names */
            $columns = $target->getColumnNames();

            $joinAlias = $alias ?: $table;

            if (!empty($columns)) {
                $select = [];
                foreach ($columns as $name) {
                    // Select alias.column as alias_column
                    $select[ $joinAlias.'.'.$name ] = $joinAlias.'_'.$name;
                }
                $query->select($select);
            }
            $joinExpr = $query->join($table, $joinAlias, $type); // it returns JoinExpression object

            // here the relationship is defined, join the it.
            if ($relationId) {
                if ($relation = static::getSchema()->getRelation($relationId)) {
                    $joinExpr->on()->equal($this->_alias.'.'.$relation['self_column'],
                        array($joinAlias.'.'.$relation['foreign_column']));
                } else {
                    throw new Exception("Relationship '$relationId' not found.");
                }
            } else {
                // find the related relatinship from defined relatinpships
                $relations = static::getSchema()->relations;
                foreach ($relations as $relationId => $relation) {
                    if (!isset($relation['foreign_schema'])) {
                        continue;
                    }

                    $fschema = new $relation['foreign_schema']();
                    if (is_a($target, $fschema->getModelClass())) {
                        $joinExpr->on()
                            ->equal($this->_alias.'.'.$relation['self_column'],
                            array($alias.'.'.$relation['foreign_column']));
                        break;
                    }
                }
            }

            if ($joinAlias) {
                $joinExpr->as($joinAlias);
            }

            return $joinExpr;
        }
        return $this->joinTable($target, $alias, $type);
    }


    public function joinTable($table, $alias = null, $type = 'LEFT')
    {
        $this->explictSelect = true;
        $query = $this->getCurrentQuery();
        return $query->join($table, $alias, $type);
    }



    public function add(BaseModel $record)
    {
        $this->_rows[] = $record;
    }

    /**
     * Set record objects.
     */
    public function setRecords(array $records)
    {
        $this->_rows = $records;
    }

    /**
     * Free resources and reset query,arguments and data.
     *
     * @return $this
     */
    public function reset()
    {
        $this->free();
        $this->_query = null;
        $this->_vars = null;
        $this->_lastSQL = null;

        return $this;
    }

    /**
     * Return pair array by columns.
     *
     * @param string $key
     * @param string $valueKey
     */
    public function asPairs($keyAccessor, $valueAccessor)
    {
        $map = [];
        foreach ($this as $item) {
            $map[$item->get($keyAccessor)] = $item->get($valueAccessor);
        }
        return $map;
    }

    public function toPairs($key, $valueKey)
    {
        return $this->asPairs($key, $valueKey);
    }

    public function toLabelValuePairs()
    {
        $items = array();
        foreach ($this as $item) {
            $items[] = array(
                'label' => $item->dataLabel(),
                'value' => $item->dataValue(),
            );
        }

        return $items;
    }

    /**
     * When cloning collection object,
     * The resources will be free, and the
     * query builder will be cloned.
     */
    public function __clone()
    {
        $this->free();

        // if we have readQuery object, we should clone the query object
        // for the new collection object.
        if ($this->_query) {
            $this->_query = clone $this->_query;
        }
    }

    public function __toString()
    {
        return $this->toSql();
    }
}
