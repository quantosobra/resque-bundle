<?php
/*
 * @copyright  Copyright (C) 2019 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com> and others, see README.md
 * @see        https://github.com/resquebundle/resque
 * @license    MIT
 */

namespace ResqueBundle\Resque\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class ResqueExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        $container->setParameter('resque.vendor_dir', $config['vendor_dir']);
        $container->setParameter('resque.app_include', $config['app_include']);
        $container->setParameter('resque.class', $config['class']);
        $container->setParameter('resque.redis.host', $config['redis']['host']);
        $container->setParameter('resque.redis.port', $config['redis']['port']);
        $container->setParameter('resque.redis.database', $config['redis']['database']);
        $container->setParameter('resque.redis.password', $config['redis']['password']);

        if (!empty($config['prefix'])) {
            $container->setParameter('resque.prefix', $config['prefix']);
            $container->getDefinition('ResqueBundle\Resque\Resque')->addMethodCall('setPrefix', [$config['prefix']]);
        }

        if (!empty($config['worker']['project_dir'])) {
            $container->setParameter('resque.worker.project_dir', $config['worker']['project_dir']);
        }

        if (!empty($config['auto_retry'])) {
            if (isset($config['auto_retry'][0])) {
                $container->getDefinition('ResqueBundle\Resque\Resque')->addMethodCall('setGlobalRetryStrategy', [$config['auto_retry'][0]]);
            } else {
                if (isset($config['auto_retry']['default'])) {
                    $container->getDefinition('ResqueBundle\Resque\Resque')->addMethodCall('setGlobalRetryStrategy', [$config['auto_retry']['default']]);
                    unset($config['auto_retry']['default']);
                }
                $container->getDefinition('ResqueBundle\Resque\Resque')->addMethodCall('setJobRetryStrategy', [$config['auto_retry']]);
            }
        }
    }
}
