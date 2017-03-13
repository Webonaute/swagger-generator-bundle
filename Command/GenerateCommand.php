<?php

namespace Draw\SwaggerGeneratorBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Martin Poirier Theoret <mpoiriert@gmail.com>
 */
class GenerateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this->setName('dsg:generate')
            ->setDescription('Generate a application base on a template and the current schema.')
            ->addArgument(
                'path',
                InputArgument::REQUIRED,
                'What is the generation path ?'
            )
            ->addOption(
                'template',
                null,
                InputOption::VALUE_REQUIRED,
                'What is template you want to use ?',
                'default'
            )
            ->addOption(
                'swagger-url',
                null,
                InputOption::VALUE_REQUIRED,
                'What is the swagger url ?'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $input->getArgument('path');

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $template = $input->getOption('template');

        $container = $this->getContainer();
        $generator = $container->get("draw_swagger_generator");
        $generator->setOutput($output);
        $swagger = $container->get("draw.swagger");
        if ($input->hasOption('swagger-url') && ($swaggerUrl = $input->getOption('swagger-url'))) {
            $schema = $swagger->extract(file_get_contents($swaggerUrl));
        } else {
            $schema = $swagger->extract(json_encode($container->getParameter("draw_swagger.schema")));
            $schema = $swagger->extract($container, $schema);
        }
        $generator->generate($schema, $path, $template);
    }
} 