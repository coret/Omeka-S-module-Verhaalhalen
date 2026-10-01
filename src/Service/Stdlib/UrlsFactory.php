<?php
namespace Verhaalhalen\Service\Stdlib;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Verhaalhalen\Stdlib\Urls;

class UrlsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');
        return new Urls(
            $services->get('ViewHelperManager')->get('Url'),
            $config['verhaalhalen']['base_url'] ?? null
        );
    }
}
