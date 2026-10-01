<?php
namespace Verhaalhalen\Service\Stdlib;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Verhaalhalen\Stdlib\BlockSource;

class BlockSourceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new BlockSource(
            $services,
            $services->get('Verhaalhalen\Settings'),
            $services->get('Omeka\Logger')
        );
    }
}
