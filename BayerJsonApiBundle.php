<?php

namespace Bayer\Bundle\JsonApiBundle;

use Bayer\Bundle\JsonApiBundle\DependencyInjection\Compiler\JsonApiHandlerCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class BayerJsonApiBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
        $container->addCompilerPass(new JsonApiHandlerCompilerPass());
    }
}
