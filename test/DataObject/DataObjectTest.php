<?php
namespace Corma\Test\DataObject;

use Corma\Test\Fixtures\ExtendedDataObject;

class DataObjectTest extends \PHPUnit_Framework_TestCase
{
    public function testGetClassName()
    {
        $this->assertEquals('ExtendedDataObject', ExtendedDataObject::getClassName());
    }

    public function testGetTableName()
    {
        $this->assertEquals('extended_data_objects', ExtendedDataObject::getTableName());
    }

    public function testSetData()
    {
        $object = new ExtendedDataObject();
        $object->setData(['myColumn'=>'asdf123']);
        $this->assertEquals('asdf123', $object->getMyColumn());
    }

    public function testGetIds()
    {
        $objects = [];
        $object = new ExtendedDataObject();
        $objects[] = $object->setId(4);
        $object = new ExtendedDataObject();
        $objects[] = $object->setId(7);
        $this->assertEquals([4, 7], ExtendedDataObject::getIds($objects));
    }
}
