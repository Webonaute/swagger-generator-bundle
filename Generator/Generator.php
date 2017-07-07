<?php

namespace Draw\SwaggerGeneratorBundle\Generator;

use Draw\Swagger\Schema\Swagger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Yaml\Yaml;
use Twig_Environment;

/**
 * @author Martin Poirier Theoret <mpoiriert@gmail.com>
 */
class Generator
{
    /**
     * @var Twig_Environment
     */
    private $twig;

    /**
     * @var Schema
     */
    private $schema;

    /**
     * @var FileWriter
     */
    private $fileWriter;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var OutputInterface $ouput
     */
    protected $output;

    private function defaultParameters()
    {
        return array(
          'module_name' => 'Project',
          'references' => array()
        );
    }

    public function __construct(Twig_Environment $twig, $templateDirectory)
    {
        $this->twig = $twig;
        $this->templateDirectory = $templateDirectory;
        $this->fileWriter = new FileWriter();
        $this->registry = new Registry();
    }

    public function setSwaggerSchema(Swagger $schema)
    {
        $this->schema = $schema;
    }

    /**
     * @param Swagger $schema
     */
    public function generate(Swagger $schema, $path, $template)
    {
        $config = $this->loadConfiguration($template);

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        foreach ($config['files'] as $fileName => $fileConfiguration) {
            $context = array(
              'swagger' => $schema,
              'file_name' => $fileName,
              'current_directory' => '@draw_swagger_generator/' . $template . '/' . dirname($fileName),

            );
            if (isset($fileConfiguration['with'])) {
                foreach ($fileConfiguration['with'] as $key => $configuration) {
                    if (isset($configuration['pathExpression'])){
                        $allExpressionResults = \JmesPath\Env::search($configuration['pathExpression'],$context);
                        $expressionResults = $this->mergeExpressionResults($allExpressionResults);
                        if (isset($configuration['unique']) && $configuration['unique'] === true && is_array($expressionResults)) {
                            $expressionResults = array_unique($expressionResults);
                        }
                        $context[$key] = $expressionResults;
                    }else {
                        if (is_string($configuration)) {
                            $context[$key] = $propertyAccessor->getValue($context, $configuration);
                        }
                    }
                }
            }

            if (isset($fileConfiguration['for'])) {
                $for = $fileConfiguration['for'];
                $key = $for['key'];
                $value = $for['value'];
                $in = $for['in'];
                $values = $propertyAccessor->getValue($context, $in);
                foreach ($values as $_key => $_value) {
                    $context[$key] = $_key;
                    $context[$value] = $_value;
                    $this->output->writeln("Render file : $path.$fileName");
                    $this->renderToFile($path, $template, $fileName, $context, $fileConfiguration);
                }
            }elseif(isset($fileConfiguration['forPathExpression'])){
                $for = $fileConfiguration['forPathExpression'];
                $key = $for['key'];
                //$value = $for['value'];

                $allExpressionResults = \JmesPath\Env::search($for['expression'],$context);
                $expressionResults = $this->mergeExpressionResults($allExpressionResults);

                if (!is_array($expressionResults) || empty($expressionResults)){
                    throw new \Exception("Expression \"$for\" returned empty result.");
                }
                $expressionResults = array_unique($expressionResults);
                foreach ($expressionResults as $expressionResult) {
                    $context[$key] = $expressionResult;
                    //$context[$value] = $_value;
                    $this->output->writeln("Render file : $path.$fileName");
                    $this->renderToFile($path, $template, $fileName, $context, $fileConfiguration);
                }
            }else{
                $this->output->writeln("Render file : $path.$fileName");
                $this->renderToFile($path, $template, $fileName, $context, $fileConfiguration);
            }
        }
    }

    private function mergeExpressionResults($allExpressionResults)
    {
        $expressionResults = [];
        foreach ($allExpressionResults as $results) {
            $expressionResults = array_merge($expressionResults, $results);
        }
        return $expressionResults;
    }

    private function getFileName($context, $realFileName, $fileConfiguration, $currentResult)
    {
        if (isset($currentResult['current.output_file'])) {
           return $currentResult['current.output_file'];
        }

        if (isset($fileConfiguration['fileName'])) {

            $currentLoader = $this->twig->getLoader();
            $this->twig->setLoader(new \Twig_Loader_Array(['filename' => $fileConfiguration['fileName']]));
            $fileName = $this->twig->render('filename', $context);
            $this->twig->setLoader($currentLoader);
            return $fileName;
        }

        return  substr($realFileName, 0, - strlen('.twig'));
    }

    private function renderToFile($path, $template, $fileName, $context, $fileConfiguration)
    {
        $context['parameters'] = $this->defaultParameters();

        try {
            $fileContent = $this->twig->render(
              '@draw_swagger_generator/' . $template . '/' . $fileName,
              $context
            );
        }catch (\Twig_Error_Loader $e){
            //prevent script to completly crash on missing template.
            //just continue and lets the user know something goes wrong.
            if ($this->output !== null){
                $this->output->writeln("<error>".$e->getMessage()."</error>");
            }
            return;
        }

        $result = $this->cleanEnvironment();

        $outputFilePath = $path . '/' . $this->getFileName($context, $fileName, $fileConfiguration, $result);

        $overwrite = !isset($fileConfiguration['overwrite']) || $fileConfiguration['overwrite'];
        $strategy = isset($fileConfiguration['fileStrategy']) ? $fileConfiguration['fileStrategy'] : FileWriter::FILE_STRATEGY_INDIVIDUAL;

        $registry = $this->getRegistry();
        if (!$registry->has('files')) {
            $registry->set('files', array());
        }

        $output = !isset($fileConfiguration['output']) ? true : $fileConfiguration['output'];
        $files = $registry->get('files');
        $files['.' . substr($outputFilePath, strlen($path))] = array(
            'path' => '.' . substr($outputFilePath, strlen($path)),
            'content' => $fileContent,
            'output' => $output,
        );

        $registry->set('files', $files);

        if (!$output) {
            return;
        }

        $this->fileWriter->writeFile($fileContent, $outputFilePath, $strategy, $overwrite);

    }

    public function setOutput(OutputInterface $output){
        $this->output = $output;
    }

    private function cleanEnvironment()
    {
        $registry = $this->getRegistry();

        $result = array();
        foreach ($registry->getArrayCopy() as $key => $value) {
            if (strpos($key, 'current.') === 0) {
                $result[$key] = $value;
                unset($registry[$key]);
            }
        }

        return $result;
    }

    public function extractOperations(Swagger $swagger)
    {
        $result = array();
        foreach($swagger->paths as $path => $pathItem) {
            foreach($pathItem as $method => $operation) {
                $result[] = compact('path','pathItem','method','operation');
            }
        }

        return $result;
    }

    /**
     * @return Registry
     */
    private function getRegistry()
    {
        $globals = $this->twig->getGlobals();
        return $globals['dsg']['registry'];
    }

    private function loadConfiguration($template)
    {
        $file = $this->templateDirectory . '/' . $template . '/config.yml';
        if(!file_exists($file)) {
            throw new \RuntimeException('Template [' . $template . '] does not exists');
        }

        return Yaml::parse(file_get_contents($file));
    }
}