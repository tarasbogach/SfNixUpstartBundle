<?php

namespace SfNix\UpstartBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @link http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class UpstartExtension extends Extension{

	/**
	 * {@inheritdoc}
	 */
	public function load(array $configs, ContainerBuilder $container){
		$configuration = new Configuration();
		$config = $this->processConfiguration($configuration, $configs);
		foreach($config['job'] as $name => &$option){
			if(!isset($option['name'])){
				$option['name'] = $name;
			}
		}
		$container->setParameter('upstart', $config);
		$locator = new FileLocator(__DIR__ . '/../Resources/config/');
		$loader = new Loader\YamlFileLoader($container, $locator);
		$loader->load('config.yml');
	}
}
