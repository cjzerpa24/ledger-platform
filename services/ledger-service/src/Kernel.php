<?php

declare(strict_types=1);

namespace App;

use App\DependencyInjection\PublicAppServicesForTestsPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        if ('test' === $this->environment) {
            $container->addCompilerPass(new PublicAppServicesForTestsPass(), PassConfig::TYPE_BEFORE_REMOVING);
        }
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
