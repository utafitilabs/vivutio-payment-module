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

namespace Vivutio\Payment\Shell;

use Vivutio\Contracts\Shell\MenuEntry;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\Payment\Controller\PaymentController;

/**
 * Payments in the menu, with lucide's wallet.
 */
final readonly class PaymentMenu implements MenuSourceInterface
{
    public function entries(): iterable
    {
        yield new MenuEntry(
            PaymentController::REGISTER,
            'Payments',
            PaymentController::READ,
            '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
            'payments',
        );
    }
}
