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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Vivutio\Contracts\Access\ConcernSourceInterface;
use Vivutio\Contracts\Partner\ChargesInterface;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Contracts\Partner\PaymentSourceInterface;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\Payment\Access\PaymentConcerns;
use Vivutio\Payment\Controller\PaymentController;
use Vivutio\Payment\Partner\RecordedPayments;
use Vivutio\Payment\Repository\RecordedPaymentRepository;
use Vivutio\Payment\Service\PaymentService;
use Vivutio\Payment\Shell\PaymentMenu;

/*
 * Every service is defined explicitly, with an id prefixed by the bundle's
 * alias; nothing is autowired or autoconfigured, so a tag is applied by hand.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html#services
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('payment.access.concerns', PaymentConcerns::class)
        ->tag(ConcernSourceInterface::TAG);
    $services->set('payment.menu', PaymentMenu::class)
        ->tag(MenuSourceInterface::TAG);

    $services->set(RecordedPaymentRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set('payment.payments', PaymentService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('clock'),
            service(ChargesInterface::class),
            service(PartnerDirectoryInterface::class),
            service(RecordedPaymentRepository::class),
        ]);
    $services->set('payment.recorded', RecordedPayments::class)
        ->args([service(RecordedPaymentRepository::class)])
        ->tag(PaymentSourceInterface::TAG);

    $services->set('payment.controller.payments', PaymentController::class)
        ->args([
            service('twig'),
            service('payment.payments'),
            service('security.csrf.token_manager'),
            service('router'),
            service('clock'),
        ])
        ->public();
    $services->alias(PaymentController::class, 'payment.controller.payments')->public();
};
