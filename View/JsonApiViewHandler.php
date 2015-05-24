<?php

namespace Bayer\Bundle\JsonApiBundle\View;

use FOS\RestBundle\View\View;
use FOS\RestBundle\View\ViewHandler;
use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class JsonApiHandler extends ContainerAware
{
    /**
     * @param ViewHandler $viewHandler
     * @param View $view
     * @param Request $request
     * @param string $format
     *
     * @return Response
     */
    public function handle(ViewHandler $viewHandler, View $view, Request $request, $format)
    {
        var_dump(get_class($viewHandler));exit;
    }
}