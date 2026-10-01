<?php
namespace Verhaalhalen\Service\Stdlib;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Verhaalhalen\Stdlib\Settings;

class SettingsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');
        return new Settings($config['verhaalhalen'] ?? []);
    }
}
