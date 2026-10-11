<?php

declare(strict_types=1);

/*
 * This file is part of the vivutio payment module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vivutio\Payment;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Payments: what the organization is owed for its bookings and tickets,
 * gathered by the core from every module, and the payments received against
 * it, recorded by hand.
 */
final class VivutioPaymentBundle extends AbstractBundle
{
    /** Configuration lives under "payment:". */
    protected string $extensionAlias = 'payment';

    /**
     * Its entities' mapping and its migrations, prepended so the installation
     * keeps the last word; each guarded.
     *
     * @see https://symfony.com/doc/current/bundles/prepend_extension.html
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'Payment' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Vivutio\\Payment\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ], prepend: true);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'Vivutio\\Payment\\Migrations' => \dirname(__DIR__).'/migrations',
                ],
            ], prepend: true);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
    }
}
