<?php
namespace Corma\Repository;

use Corma\DataObject\DataObject;
use Corma\DataObject\DataObjectInterface;
use Corma\DataObject\DataObjectEvent;
use Corma\Exception\ClassNotFoundException;
use Corma\Exception\InvalidArgumentException;
use Corma\Exception\InvalidClassException;
use Corma\ObjectMapper;
use Corma\QueryHelper\QueryHelperInterface;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Statement;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * An object repository manages all creation, persistence, retrieval, deletion of objects of a particular class.
 *
 * This base class is used when all no repository is defined for a class, and is the base for all other repository classes.
 */
class ObjectRepository implements ObjectRepositoryInterface
{
    /**
     * @var Connection
     */
    protected $db;
    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @var QueryHelperInterface
     */
    protected $queryHelper;

    /**
     * @var string
     */
    protected $className;

    /**
     * @var string
     */
    protected $shortClassName;

    /**
     * @var CacheProvider
     */
    protected $cache;

    /**
     * @var ObjectMapper
     */
    protected $objectMapper;

    protected $objectByIdCache;

    /**
     * @var array Array of dependencies passed as constructor parameters to the data objects
     */
    protected $objectDependencies = [];

    public function __construct(Connection $db, ObjectMapper $objectMapper, CacheProvider $cache, EventDispatcherInterface $dispatcher = null)
    {
        $this->db = $db;
        $this->objectMapper = $objectMapper;
        $this->queryHelper = $objectMapper->getQueryHelper();
        $this->cache = $cache;
        $this->dispatcher = $dispatcher;
    }

    public function create()
    {
        $class = $this->getClassName();
        if(empty($this->objectDependencies)) {
            return new $class();
        } else {
            $reflectionClass = new \ReflectionClass($class);
            return $reflectionClass->newInstanceArgs($this->objectDependencies);
        }
    }

    public function find($id, $useCache = true)
    {
        if($useCache && isset($this->objectByIdCache[$id])) {
            return $this->objectByIdCache[$id];
        }
        $qb = $this->queryHelper->buildSelectQuery($this->getTableName(), 'main.*', ['main.id'=>$id]);
        $instance = $this->fetchOne($qb);
        if($instance) {
            $this->objectByIdCache[$id] = $instance;
        }
        return $instance;
    }

    public function findByIds(array $ids, $useCache = true)
    {
        $instances = [];
        if($useCache) {
            foreach($ids as $i => $id) {
                if(isset($this->objectByIdCache[$id])) {
                    $instances[] = $this->objectByIdCache[$id];
                    unset($ids[$i]);
                }
            }
        }

        if(!empty($ids)) {
            $qb = $this->queryHelper->buildSelectQuery($this->getTableName(), 'main.*', ['main.id'=>$ids]);
            $newInstances = $this->fetchAll($qb);
            /** @var $instance DataObjectInterface */
            foreach($newInstances as $instance) {
                $this->objectByIdCache[$instance->getId()] = $instance;
            }
            $instances = array_merge($instances, $newInstances);
        }

        return $instances;
    }

    public function findAll()
    {
        $dbColumns = $this->queryHelper->getDbColumns($this->getTableName());
        if(isset($dbColumns['isDeleted'])) {
            $qb = $this->queryHelper->buildSelectQuery($this->getTableName(), 'main.*', ['isDeleted'=>0]);
        } else {
            $qb = $this->queryHelper->buildSelectQuery($this->getTableName());
        }
        $all = $this->fetchAll($qb);
        array_walk($all, function(DataObjectInterface $object){
            $this->objectByIdCache[$object->getId()] = $object;
        });
        return $all;
    }

    public function findBy(array $criteria, array $orderBy = [], $limit = null, $offset = null)
    {
        $qb = $this->queryHelper->buildSelectQuery($this->getTableName(), 'main.*', $criteria, $orderBy);
        if($limit) {
            $qb->setMaxResults($limit);
            if($offset) {
                $qb->setFirstResult($offset);
            }
        }
        return $this->fetchAll($qb);
    }

    public function findOneBy(array $criteria)
    {
        $qb = $this->queryHelper->buildSelectQuery($this->getTableName(), 'main.*', $criteria);
        $qb->setMaxResults(1);
        return $this->fetchOne($qb);
    }

    /**
     * Loads a foreign relationship where a property on the supplied objects references an id for another object
     *
     * $foreignIdColumn defaults to foreignObjectId if the $className is Namespace\\ForeignObject
     *
     * @param DataObjectInterface[] $objects
     * @param string $className Class name of foreign object to load
     * @param string $foreignIdColumn Property on this object that relates to the foreign tables id
     * @return DataObjectInterface[] Loaded objects keyed by id
     */
    public function loadOne(array $objects, $className, $foreignIdColumn = null)
    {
        $foreignIdColumn = $foreignIdColumn ? $foreignIdColumn : $this->idColumnFromClass($className);

        return $this->objectMapper->getRelationshipLoader()->loadOne($objects, $className, $foreignIdColumn);
    }

    /**
     * Loads a foreign relationship where a column on another object references the id for the supplied object
     *
     * $foreignColumn defaults to objectId for objects of class Namespace\\Object
     *
     * @param DataObjectInterface[] $objects
     * @param string $className Class name of foreign objects to load
     * @param string $foreignColumn Property on foreign object that relates to this object id
     * @return DataObjectInterface[] Loaded objects keyed by id
     */
    public function loadMany(array $objects, $className, $foreignColumn = null)
    {
        $foreignColumn = $foreignColumn ? $foreignColumn : $this->idColumnFromClass($this->getClassName());

        return $this->objectMapper->getRelationshipLoader()->loadMany($objects, $className, $foreignColumn);
    }

    /**
     * Loads objects of the foreign class onto the supplied objects linked by a link table containing the id's of both objects
     *
     * @param DataObjectInterface[] $objects
     * @param string $className Class name of foreign objects to load
     * @param string $linkTable Table that links two objects together
     * @param string $idColumn Column on link table = the id on this object
     * @param string $foreignIdColumn Column on link table = the id on the foreign object table
     * @return DataObjectInterface[] Loaded objects keyed by id
     */
    public function loadManyToMany(array $objects, $className, $linkTable, $idColumn = null, $foreignIdColumn = null)
    {
        $idColumn = $idColumn ? $idColumn : $this->idColumnFromClass($this->getClassName());
        $foreignIdColumn = $foreignIdColumn ? $foreignIdColumn : $this->idColumnFromClass($className);

        return $this->objectMapper->getRelationshipLoader()->loadManyToMany($objects, $className, $linkTable, $idColumn, $foreignIdColumn);
    }

    /**
     * Returns the full class name of the object managed by the repository.
     *
     * @return string
     */
    public function getClassName()
    {
        if($this->className) {
            return $this->className;
        }

        $class = explode('\\', get_called_class());
        $objectClass = [];
        foreach($class as $classPart) {
            if($classPart != 'Repository') {
                $objectClass[] = str_replace('Repository', '', $classPart);
            }
        }
        return $this->className = implode('\\', $objectClass);
    }

    /**
     * Set the class name, this allows the base class to be used for data objects without a repository
     *
     * This method is deliberately excluded from the interface.
     *
     * @param string $className
     * @return $this
     */
    public function setClassName($className)
    {
        $this->className = $className;
        return $this;
    }

    /**
     * Return the database table this repository manages
     *
     * @return string
     */
    public function getTableName()
    {
        $class = $this->getClassName();
        if(!class_exists($class)) {
            throw new ClassNotFoundException("$class not found");
        } else if(!class_implements($class, DataObjectInterface::class)) {
            throw new InvalidClassException("$class must implement DataObjectInterface");
        }
        return $class::getTableName();
    }

    /**
     * Persists the object to the database
     *
     * @param DataObjectInterface $object
     * @return DataObjectInterface
     * @throws \Exception
     */
    public function save(DataObjectInterface $object)
    {
        $this->checkArgument($object);

        $this->dispatchEvents('beforeSave', $object);

        if($object->getId()) {
            $this->update($object);
        } else {
            $this->insert($object);
        }

        $this->dispatchEvents('afterSave', $object);
        return $object;
    }

    /**
     * Persists all supplied objects into the database
     *
     * @param DataObjectInterface[] $objects
     * @return int
     */
    public function saveAll(array $objects)
    {
        if(empty($objects)) {
            return 0;
        }

        foreach($objects as $object) {
            $this->checkArgument($object);
            $this->dispatchEvents('beforeSave', $object);
            if($object->getId()) {
                $this->dispatchEvents('beforeUpdate', $object);
            } else {
                $this->dispatchEvents('beforeInsert', $object);
            }
        }

        $columns = $this->queryHelper->getDbColumns($objects[0]->getTableName());
        $rows = [];
        foreach($objects as $object) {
            $data = $object->getData();
            foreach($data as $prop => $value) {
                if(!isset($columns[$prop])) {
                    unset($data[$prop]);
                }
            }
            $rows[] = $data;
        }

        $lastId = null;
        $rows = $this->queryHelper->massUpsert($this->getTableName(), $rows, $lastId);

        foreach($objects as $object) {
            if($object->getId()) {
                $this->dispatchEvents('afterUpdate', $object);
            } else {
                if($lastId) {
                    $object->setId($lastId);
                    $lastId++;
                }
                $this->dispatchEvents('afterInsert', $object);
            }
            $this->dispatchEvents('afterSave', $object);
        }

        return $rows;
    }

    /**
     * Removes the object from the database
     *
     * @param DataObjectInterface $object
     * @throws \Doctrine\DBAL\Exception\InvalidArgumentException
     */
    public function delete(DataObjectInterface $object)
    {
        $this->checkArgument($object);
        $this->dispatchEvents('beforeDelete', $object);

        $columns = $this->queryHelper->getDbColumns($object->getTableName());

        if(isset($columns['isDeleted'])) {
            $this->db->update($object->getTableName(), [$this->db->quoteIdentifier('isDeleted')=>1], ['id'=>$object->getId()]);
        } else {
            $this->db->delete($object->getTableName(), ['id'=>$object->getId()]);
        }

        $object->setDeleted(true);

        $this->dispatchEvents('afterDelete', $object);
    }

    /**
     * Deletes all objects by id
     *
     * @param DataObjectInterface[] $objects
     * @return int Number of db rows effected
     */
    public function deleteAll(array $objects)
    {
        if(empty($objects)) {
            return 0;
        }

        foreach($objects as $object) {
            $this->checkArgument($object);
            $this->dispatchEvents('beforeDelete', $object);
        }

        $columns = $this->queryHelper->getDbColumns($objects[0]->getTableName());
        $ids = DataObject::getIds($objects);
        if(isset($columns['isDeleted'])) {
            $rows = $this->queryHelper->massUpdate($this->getTableName(), ['isDeleted'=>1], ['id'=>$ids]);
        } else {
            $rows = $this->queryHelper->massDelete($this->getTableName(), ['id'=>$ids]);
        }

        foreach($objects as $object) {
            $object->setDeleted(true);
            $this->dispatchEvents('afterDelete', $object);
        }

        return $rows;
    }

    /**
     * Inserts this DataObject into the database
     *
     * @param DataObjectInterface $object
     * @return DataObjectInterface The newly persisted object with id set
     */
    protected function insert(DataObjectInterface $object)
    {
        $this->dispatchEvents('beforeInsert', $object);

        $queryParams = $this->buildQueryParams($object);

        $this->db->insert($object->getTableName(), $queryParams);

        $id = $this->queryHelper->getLastInsertId($object->getTableName());
        $object->setId($id);

        $this->dispatchEvents('afterInsert', $object);
        return $object;
    }

    /**
     *  Update this DataObject's table row
     *
     * @param DataObjectInterface $object
     */
    protected function update(DataObjectInterface $object)
    {
        $this->dispatchEvents('beforeUpdate', $object);

        $queryParams = $this->buildQueryParams($object);

        $this->db->update($object->getTableName(), $queryParams, ['id'=>$object->getId()]);

        $this->dispatchEvents('afterUpdate', $object);
    }

    /**
     * Saves the object, and executes the supplied callback, wrapping in a try / catch and transaction.
     * This meant to be used to save associated relationships when overriding the the save() method.
     *
     * Functions will receive an array parameter with the object that has just been saved
     *
     * @param DataObjectInterface $object
     * @param callable $afterSave
     * @throws \Exception
     */
    protected function saveWith(DataObjectInterface $object, callable $afterSave)
    {
        $this->db->beginTransaction();
        try {
            self::save($object);
            $afterSave([$object]);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Saves the objects, and executes the supplied callback, wrapping in a try / catch and transaction.
     * This meant to be used to save associated relationships when overriding the the saveAll() method.
     *
     * Functions will receive an array parameter with the objects that have just been saved
     *
     * @param DataObjectInterface[] $objects
     * @param callable $afterSave
     * @throws \Exception
     */
    protected function saveAllWith(array $objects, callable $afterSave)
    {
        $this->db->beginTransaction();
        try {
            self::saveAll($objects);
            $afterSave($objects);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Build parameters for insert or update
     * @param DataObjectInterface $object
     * @return array
     */
    protected function buildQueryParams(DataObjectInterface $object)
    {
        $queryParams = [];
        $dbColumns = $this->queryHelper->getDbColumns($object->getTableName());
        foreach ($object->getData() as $column => $value) {
            if (isset($dbColumns[$column])) {
                if ($column == 'id') {
                    continue;
                } else if($value === null && $dbColumns[$column] === false) {
                    continue;
                } else {
                    $queryParams[$this->db->quoteIdentifier($column)] = $value;
                }
            }
        }
        return $queryParams;
    }

    /**
     * @param QueryBuilder $qb
     * @return DataObjectInterface[]
     */
    protected function fetchAll(QueryBuilder $qb)
    {
        /** @var Statement $statement */
        $statement = $qb->execute();
        $objects = $statement->fetchAll(\PDO::FETCH_CLASS, $this->getClassName(), $this->objectDependencies);
        foreach($objects as $object) {
            $this->dispatchEvents('loaded', $object);
        }
        return $objects;
    }

    /**
     * @param QueryBuilder $qb
     * @return DataObjectInterface
     */
    protected function fetchOne(QueryBuilder $qb)
    {
        $statement = $qb->setMaxResults(1)->execute();
        $statement->setFetchMode(\PDO::FETCH_CLASS, $this->getClassName(), $this->objectDependencies);
        $object = $statement->fetch();
        if($object) {
            $this->dispatchEvents('loaded', $object);
        }
        return $object;
    }

    /**
     * Dispatches two events one generic DataObject one and a class specific event
     *
     * @param string $eventName
     * @param DataObjectInterface $object
     */
    protected function dispatchEvents($eventName, DataObjectInterface $object)
    {
        if(!$this->dispatcher) {
            return;
        }

        $event = new DataObjectEvent($object);
        $this->dispatcher->dispatch('DataObject.'.$eventName, $event);
        $class = $this->getShortClassName();
        $this->dispatcher->dispatch('DataObject.'.$class.'.'.$eventName, $event);
    }

    /**
     * Returns the class name minus namespace of the object managed by the repository.
     *
     * @return string
     */
    protected function getShortClassName()
    {
        if($this->shortClassName) {
            return $this->shortClassName;
        }
        $class = $this->getClassName();
        return $this->shortClassName = substr($class, strrpos($class, '\\') + 1);
    }

    /**
     * Restores a single DataObject from cached data
     *
     * @param array $data
     * @return DataObjectInterface
     */
    protected function restoreFromCache(array $data)
    {
        $object = $this->create();
        $object->setData($data);
        $this->objectByIdCache[$object->getId()] = $object;
        return $object;
    }

    /**
     * Restores DataObjects from the cache at the key specified
     *
     * @param string $key Cache key
     * @return DataObjectInterface[]
     */
    protected function restoreAllFromCache($key)
    {
        $cachedData = $this->cache->fetch($key);
        $objectsFromCache = [];
        foreach ($cachedData as $data) {
            $objectsFromCache[] = $this->restoreFromCache($data);
        }
        return $objectsFromCache;
    }

    /**
     * Stores DataObjects in cache at the key specified
     *
     * @param DataObjectInterface[] $objects
     * @param string $key
     * @param int $lifeTime
     */
    protected function storeAllInCache(array $objects, $key, $lifeTime = 0)
    {
        $dataToCache = [];
        foreach ($objects as $object) {
            $dataToCache[] = $object->getData();
            $this->objectByIdCache[$object->getId()] = $object;
        }
        $this->cache->save($key, $dataToCache, $lifeTime);
    }

    /**
     * @param DataObjectInterface $object
     */
    protected function checkArgument(DataObjectInterface $object)
    {
        $className = $this->getClassName();
        if (!($object instanceof $className)) {
            throw new InvalidArgumentException("Object must be instance of $className");
        }
    }

    /**
     * @param string $className
     * @param string $suffix
     * @return string
     */
    protected function idColumnFromClass($className, $suffix = 'Id')
    {
        return $this->objectMapper->getInflector()->idColumnFromClass($className, $suffix);
    }
}
