<?php

namespace Draw\SwaggerGeneratorBundle\Tests\Generator;

use Draw\SwaggerGeneratorBundle\Generator\FileWriter;
use PHPUnit\Framework\TestCase;

/**
 * @author Martin Poirier Theoret <mpoiriert@gmail.com>
 */
class FileWriterTest extends TestCase
{
    public function provideWrite()
    {
        $tempFileName = sys_get_temp_dir() . '/' . uniqid() . '/temp.txt';

        $fileWriter = new FileWriter();

        return array(
            array('test',$fileWriter, $tempFileName,'test',FileWriter::FILE_STRATEGY_APPEND, true),
            array('testtest',$fileWriter, $tempFileName,'test',FileWriter::FILE_STRATEGY_APPEND, true),
            array('testtest',$fileWriter, $tempFileName,'test',FileWriter::FILE_STRATEGY_INDIVIDUAL, false),
            array('testtesttest',$fileWriter, $tempFileName,'test',FileWriter::FILE_STRATEGY_APPEND, false),
            array('test',new FileWriter(), $tempFileName,'test',FileWriter::FILE_STRATEGY_APPEND, true)
        );
    }

    /**
     * @dataProvider provideWrite
     */
    public function testWrite($expected, FileWriter $fileWriter, $fileName, $content, $strategy, $overwrite)
    {
        $fileWriter->writeFile($content, $fileName, $strategy, $overwrite);
        $this->assertFileExists($fileName);
        $this->assertEquals($expected, file_get_contents($fileName));
    }
} 