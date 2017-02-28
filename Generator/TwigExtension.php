<?php

namespace Draw\SwaggerGeneratorBundle\Generator;

use Draw\Swagger\Schema\BodyParameter;
use Draw\Swagger\Schema\Operation;
use Draw\Swagger\Schema\PathParameter;
use Draw\Swagger\Schema\QueryParameter;
use Draw\Swagger\Schema\Schema;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Twig_Environment;
use Twig_SimpleFilter;

/**
 * @author Martin Poirier Theoret <mpoiriert@gmail.com>
 */
class TwigExtension extends \Twig_Extension
{
    /**
     * @var Twig_Environment
     */
    private $environment;

    private $configuration;

    /**
     * @var Generator
     */
    private $generator;

    public function __construct(array $configuration)
    {
        $this->configuration = $configuration;
    }

    public function getFunctions()
    {
        $methods = [];
        $methods[] = new \Twig_SimpleFunction("getModelByOperation", [$this, 'getModelByOperation']);
        return $methods;
    }

    public function getTests()
    {
        $methods = [];
        $methods[] = new \Twig_SimpleTest("instanceof", [$this, "isInstanceof"]);
        return $methods;
    }

    public function initRuntime(Twig_Environment $environment)
    {
        $this->environment = $environment;
        $this->environment->getExtension('escaper')->setDefaultStrategy(false);
        parent::initRuntime($environment);
    }

    public function getGlobals()
    {
        $registry = $this->configuration['registry'];

        return array(
            'dsg' => array(
                'registry' => new Registry($registry),
            ),
        );
    }

    public function getFilters()
    {
        $options = array('is_safe' => array('html'));
        $filters = array();

        $filters[] = new Twig_SimpleFilter('path', array($this, 'pathFilter'), $options);
        $filters[] = new Twig_SimpleFilter('is_class', array($this, 'isClass'), $options);
        $filters[] = new Twig_SimpleFilter('filter_map', array($this, 'filterMap'), $options);
        $filters[] = new Twig_SimpleFilter('path_key_map', array($this, 'pathKeyMap'), $options);
        $filters[] = new Twig_SimpleFilter('key_filter', array($this, 'keyFilter'), $options);
        $filters[] = new Twig_SimpleFilter('convert_type', array($this, 'convertType'), $options);
        $filters[] = new Twig_SimpleFilter(
            'extract_operation_parameters', array(
            $this,
            'extractOperationParameters',
        ), $options
        );

        foreach ($this->configuration['php_functions'] as $phpFunctionName => $configuration) {
            $position = $configuration['argumentPosition'];
            $callable = function () use ($phpFunctionName, $position) {
                $arguments = func_get_args();
                $argument = array_shift($arguments);

                array_splice($arguments, $position, 0, array($argument));

                $result = call_user_func_array($phpFunctionName, $arguments);

                return $result;
            };

            $filters[] = new \Twig_SimpleFilter($phpFunctionName, $callable, $options);
        }

        foreach ($this->configuration['filters'] as $filterName => $filterConfiguration) {
            switch ($filterConfiguration['type']) {
                case 'chain':
                    $extension = $this;
                    $chain = $filterConfiguration['parameters']['chain'];
                    $filters[] = new \Twig_SimpleFilter(
                        $filterName,
                        function ($argument) use ($extension, $chain, $filterName) {
                            return $extension->callChainFilter($argument, $chain, $filterName);
                        }
                    );
                    break;

            }
        }

        return $filters;
    }

    /**
     * @param $var
     * @param $instance
     * @return bool
     */
    public function isInstanceof($var, $instance) {
        return  $var instanceof $instance;
    }

    public function getModelByOperation(Operation $operation, $prefix = ''){
        /** @var Schema $param */
        if (isset($operation->responses[200])) {
            $schema = $operation->responses[200]->schema;
            $model = $prefix.str_replace('#/definitions/', '', $schema->ref);
        }else{
            $model = "";
        }
        return $model;
    }

    public function pathFilter($argument, $path)
    {
        return PropertyAccess::createPropertyAccessor()->getValue($argument, $path);
    }

    public function filterMap($argument, $filterName, array $options = array())
    {
        if (!is_array($argument)) {
            $argument = iterator_to_array($argument);
        }
        $callable = $this->environment->getFilter($filterName)->getCallable();
        $result = array();
        foreach ($argument as $key => $value) {
            $arguments = $options;
            array_unshift($arguments, $value);
            $result[$key] = call_user_func_array($callable, $arguments);
        }

        return $result;

    }

    public function callChainFilter($argument, $chain, $chainName)
    {
        foreach ($chain as $configuration) {
            $filterName = $configuration['filterName'];
            $arguments = $configuration['arguments'];
            $filter = $this->environment->getFilter($filterName);
            array_unshift($arguments, $argument);
            $argument = call_user_func_array($filter->getCallable(), $arguments);
        }

        return $argument;
    }

    public function keyFilter($argument, $filterName, array $options = array())
    {
        $result = array();

        $keys = $this->filterMap(
            array_combine($keys = array_keys($argument), $keys),
            $filterName,
            $options
        );

        foreach ($keys as $original => $modified) {
            $result[$modified] = $argument[$original];
        }

        return $result;
    }

    public function pathKeyMap($argument, $path)
    {
        if (!is_array($argument)) {
            $argument = iterator_to_array($argument);
        }

        $result = array();
        foreach ($argument as $key => $value) {
            $result[PropertyAccess::createPropertyAccessor()->getValue($value, $path)][$key] = $value;
        }

        return $result;
    }

    public function extractOperationParameters(Operation $operation, $typeMapping)
    {
        $parameters = new \stdClass();
        $parameters->path = new \stdClass();
        $parameters->query = new \stdClass();
        $parameters->body = new \stdClass();
        if (!isset($operation->parameters)) {
            return $parameters;
        }

        foreach ($operation->parameters as $parameter) {
            if ($parameter instanceof PathParameter){
                $type = "path";
            }elseif ($parameter instanceof QueryParameter){
                $type = "query";
            }elseif ($parameter instanceof BodyParameter){
                $type = "body";
            }else{
                //not supported.
                continue;
            }

            $parameters->$type->{$parameter->name} = $this->convertType($parameter, $typeMapping);
        }

        return $parameters;
    }

    public function convertType($schema, array $mapping)
    {

        if (isset($schema->ref)) {
            $type = $schema->ref;
        } elseif (isset($schema->type)) {
            $type = $schema->type;
        } else {
            $type = "string";
            // throw new \RuntimeException('Cannot detect type');
        }

        if (array_key_exists($type, $mapping)) {
            return $mapping[$type];
        }

        if (strpos($type, '#/definitions/') === 0) {
            return call_user_func(
                $this->environment->getFilter('class_name')->getCallable(),
                str_replace('#/definitions/', '', $type)
            );
        }

        return $type;
    }

    public function isClass($type, array $mapping)
    {
        return !(array_key_exists($type, $mapping) || in_array($type, $mapping));
    }

    public function getName()
    {
        return 'draw_swagger_generator';
    }
} 