<?php
namespace Verhaalhalen\Service\BlockLayout;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Verhaalhalen\Site\BlockLayout\Verhaalhalen;

class VerhaalhalenFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Verhaalhalen($services->get('Verhaalhalen\Urls'));
    }
}
