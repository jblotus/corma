<?php
namespace Corma\DataObject;


/**
 * An object that can be persisted and retrieved by Corma
 */
interface DataObjectInterface
{
    /**
     * Get the table this data object is persisted in
     *
     * @return string
     */
    public static function getTableName();

    /**
     * Get class minus namespace
     *
     * @return string
     */
    public static function getClassName();

    /**
     * @param DataObjectInterface[] $objects
     * @return array
     */
    public static function getIds(array $objects);

    /**
     * @return string
     */
    public function getId();

    /**
     * @param string $id
     * @return $this
     */
    public function setId($id);

    /**
     * @param bool $isDeleted
     * @return $this
     */
    public function setDeleted($isDeleted);

    /**
     * @return bool
     */
    public function isDeleted();

    /**
     * Sets the data provided to the properties of the object. Used when restoring objects from cache.
     *
     * @param array $data
     * @return $this
     */
    public function setData(array $data);

    /**
     * Returns all scalar data (i.e. no objects / arrays). 
     * Used when persisting objects to the database and cache. 
     *
     * @return array
     */
    public function getData();
}
