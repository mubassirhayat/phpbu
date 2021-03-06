<?php
namespace phpbu\Backup\Cleaner;

use phpbu\Util\String;

/**
 * TestCase
 *
 * @package    phpbu
 * @subpackage tests
 * @author     Sebastian Feldmann <sebastian@phpbu.de>
 * @copyright  Sebastian Feldmann <sebastian@phpbu.de>
 * @license    http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @link       http://www.phpbu.de/
 * @since      Class available since Release 1.0.0
 */
class TestCase extends \PHPUnit_Framework_TestCase
{
    /**
     * Test execution time
     *
     * @var integer
     */
    protected $time;

    /**
     * Create a list of File stubs
     *
     * @param  integer $size      Size in byte the stubs will return on getSize()
     * @param  integer $amount    Amount of stubs in list
     * @return array<splFileInfo>
     */
    protected function getFileMockList(array $files)
    {
        $list = array();
        foreach ($files as $i => $file) {
            $index        = isset($file['mTime'])
                          ? date('YmdHis', $file['mTime'])
                          : '201401' . str_pad($i, 2, '0', STR_PAD_LEFT) . '0000';
            $list[$index] = $this->getFileMock(
                isset($file['size'])            ? $file['size']            : null,
                isset($file['shouldBeDeleted']) ? $file['shouldBeDeleted'] : null,
                isset($file['mTime'])           ? $file['mTime']           : null
            );
        }
        return $list;
    }

    /**
     * Create a list of File stubs
     *
     * @param  integer $size            Size in byte the stubs will return on getSize()
     * @param  boolean $shouldBeDeleted Should this file be deleted after cleanup
     * @param  integer $mTime           Last modification date the stub will return on getMTime()
     * @return array<splFileInfo>
     */
    protected function getFileMock($size, $shouldBeDeleted, $mTime)
    {
        /* @var $fileStub PHPUnit_Framework_MockObject */
        $fileStub = $this->getMockBuilder('\\phpbu\\Backup\\File')
                         ->disableOriginalConstructor()
                         ->getMock();
        $fileStub->method('getMTime')->willReturn($mTime);
        $fileStub->method('getSize')->willReturn($size);
        $fileStub->method('isWritable')->willReturn(true);
        if ($shouldBeDeleted) {
            $fileStub->expects($this->once())
                     ->method('unlink');
        }

        return $fileStub;
    }

    /**
     * Get a fake last modified date
     *
     * @param  string $offset
     * @return integer
     */
    protected function getMTime($offset)
    {
        return $this->getTime() - String::toTime($offset);
    }

    /**
     * Return the current time
     *
     * @return integer
     */
    protected function getTime()
    {
        if (null == $this->time) {
            $this->time = time();
        }
        return $this->time;
    }
}
