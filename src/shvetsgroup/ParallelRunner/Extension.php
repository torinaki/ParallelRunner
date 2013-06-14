<?php
/**
 * @copyright 2013 Alexander Shvets
 * @license MIT
 */

namespace shvetsgroup\ParallelRunner;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition,
  Symfony\Component\DependencyInjection\ContainerBuilder,
  Symfony\Component\DependencyInjection\Reference;

use Behat\Behat\Extension\ExtensionInterface;


class Extension implements ExtensionInterface
{
    /**
     * {@inheritdoc}
     */
    public function load(array $config, ContainerBuilder $container)
    {
        // do not load extension at all if not set parallel processes count
        if ($config['process_count'] < 2) {
            return;
        }
        $container->setParameter(
            'behat.console.command.class',
            '\shvetsgroup\ParallelRunner\Console\Command\ParallelRunnerCommand'
        );
        $container
          ->register(
              'parallel_runner.console.processor.parallel',
              '\shvetsgroup\ParallelRunner\Console\Processor\ParallelProcessor'
          )
          ->addArgument(new Reference('service_container'))
          ->addTag('behat.console.processor');

        $this->initFormatters($config, $container);
        $container
          ->register('parallel.service.event', '\shvetsgroup\ParallelRunner\Service\EventService')
          ->addArgument(new Reference('behat.event_dispatcher'));

        $container->setParameter('parallel.process_count', $config['process_count']);
        $container->setParameter('parallel.profiles', $config['profiles']);
    }

    public function initFormatters(array $config, ContainerBuilder $container)
    {
        // TODO: remove not supported formatters
        $container->setParameter('behat.formatter.classes', array(
            'junit' => '\shvetsgroup\ParallelRunner\Formatter\JUnitFormatter',
            'progress' => '\shvetsgroup\ParallelRunner\Formatter\ProgressFormatter',
        ));
    }

    /**
     * Setup configuration for this extension.
     *
     * @param ArrayNodeDefinition $builder
     *   ArrayNodeDefinition instance.
     */
    public function getConfig(ArrayNodeDefinition $builder)
    {
        $builder
            ->children()
                ->scalarNode('process_count')
                    ->defaultValue(2)
                ->end()
                ->arrayNode('profiles')
                    ->defaultValue(array())
                    ->prototype('scalar')
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Returns compiler passes used by mink extension.
     *
     * @return array
     */
    public function getCompilerPasses()
    {
        return array();
    }
}

return new Extension();