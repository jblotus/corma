<?php
namespace Corma\Repository;

use Corma\DataObject\DataObjectInterface;
use Doctrine\Common\Persistence\ObjectRepository as DoctrineObjectRepository;

/**
 * Interface for object repositories.
 * An object repository manages all creation, persistence, retrieval, deletion of objects of a particular class.
 */
interface ObjectRepositoryInterface extends DoctrineObjectRepository
{
    /**
     * Creates a new instance of the object
     *
     * @return DataObjectInterface
     */
    public function create();

    /**
     * @param mixed $id The Identifier
     * @param bool $useCache Use cache?
     * @return mixed
     */
    public function find($id, $useCache = true);

    /**
     * Find one or more data objects by id
     *
     * @param array $ids
     * @param bool $useCache Use cache?
     * @return \Corma\DataObject\DataObjectInterface[]
     */
    public function findByIds(array $ids, $useCache = true);

    /**
     * Return the database table this repository manages
     *
     * @return string
     */
    public function getTableName();

    /**
     * Persists the object to the database
     *
     * @param DataObjectInterface $object
     * @return DataObjectInterface
     */
    public function save(DataObjectInterface $object);

    /**
     * Persists all supplied objects into the database
     **
     * @param DataObjectInterface[] $objects
     * @return int The number of effected rows
     */
    public function saveAll(array $objects);

    /**
     * Removes the object from the database
     *
     * @param DataObjectInterface $object
     * @throws \Doctrine\DBAL\Exception\InvalidArgumentException
     */
    public function delete(DataObjectInterface $object);

    /**
     * Deletes all objects by id
     *
     * @param DataObjectInterface[] $objects
     */
    public function deleteAll(array $objects);

    /**
     * Loads a foreign relationship where a property on the supplied objects references an id for another object.
     *
     * Can be used to load a one-to-one relationship or the "one" side of a one-to-many relationship.
     *
     * @param DataObjectInterface[] $objects
     * @param string $className Class name of foreign object to load
     * @param string $foreignIdColumn Column / property on objects that relates to the foreign table's id
     * @return DataObjectInterface[] Loaded objects keyed by id
     */
    public function loadOne(array $objects, $className, $foreignIdColumn = null);

    /**
     * Loads a foreign relationship where a column on another object references the id for the supplied objects.
     *
     * Used to load the "many" side of a one-to-many relationship.
     *
     * @param DataObjectInterface[] $objects
     * @param string $className Class name of foreign objects to load
     * @param string $foreignColumn Column / property on foreign object that relates to the objects id
     * @return DataObjectInterface[] Loaded objects keyed by id
     */
    public function loadMany(array $objects, $className, $foreignColumn = null);

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
    public function loadManyToMany(array $objects, $className, $linkTable, $idColumn = null, $foreignIdColumn = null);
}
