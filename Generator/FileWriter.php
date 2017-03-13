<?php

namespace Draw\SwaggerGeneratorBundle\Generator;

/**
 * @author Martin Poirier Theoret <mpoiriert@gmail.com>
 */
class FileWriter
{
    const FILE_STRATEGY_APPEND = 'append';
    const FILE_STRATEGY_INDIVIDUAL = 'individual';

    private $renderedFiles = array();

    public function reset()
    {
        $this->renderedFiles = array();
    }

    public function writeFile($fileContent, $fileName, $strategy, $overwrite = true)
    {
        $first = !in_array($fileName, $this->renderedFiles);

        if ($this->allowedToWrite($fileName, $overwrite, $strategy) === false){
            return;
        }

        if ($first && file_exists($fileName)) {
            unlink($fileName);
        }

        $dirName = dirname($fileName);
        if (!is_dir($dirName)) {
            mkdir($dirName, 0777, true);
        }

        if ($strategy == self::FILE_STRATEGY_APPEND && file_exists($fileName)) {
            $fileContent = file_get_contents($fileName).$fileContent;
        }

        file_put_contents($fileName, $fileContent);
        $this->renderedFiles[] = $fileName;
    }

    protected function allowedToWrite($fileName, $overwrite = true, $strategy = self::FILE_STRATEGY_INDIVIDUAL)
    {
        $write = false;

        if (!file_exists($fileName) ||
            $overwrite === true ||
            $strategy == self::FILE_STRATEGY_APPEND
        ) {
            $write = true;
        }

        return $write;
    }
} 