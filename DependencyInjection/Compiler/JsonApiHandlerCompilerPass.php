<?php

namespace Bayer\Bundle\JsonApiBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Reference;

class JsonApiHandlerCompilerPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasDefinition('fos_rest.view_handler.default')) {
            throw new \RuntimeException('fos_rest.view_handler.default must be defined');
        }

        $definition = $container->findDefinition('fos_rest.view_handler.default');
        $definition->addMethodCall(
            'registerHandler',
            array(
                'json', array(new Reference('bayer_json_api.json_api_handler'), 'handle')
            )
        );
    }
}
