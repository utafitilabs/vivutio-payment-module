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

namespace Vivutio\Payment\Access;

use Vivutio\Contracts\Access\Concern;
use Vivutio\Contracts\Access\ConcernSourceInterface;
use Vivutio\Contracts\Access\Scope;
use Vivutio\Contracts\Access\Verb;

/**
 * What a position may be granted over payments: reading what is owed and
 * paid, recording a payment, and striking one out.
 */
final readonly class PaymentConcerns implements ConcernSourceInterface
{
    public const string PAYMENTS = 'payments';

    public function declaredBy(): string
    {
        return 'Payment';
    }

    public function concerns(): iterable
    {
        yield new Concern(
            key: self::PAYMENTS,
            label: 'Payments',
            description: 'What the organization is owed for its bookings and tickets, and the payments received: reading them, recording a payment, and striking one out.',
            verbs: [Verb::Read, Verb::Record, Verb::Manage],
            scopes: [Scope::ORGANIZATION],
            moduleSlug: 'payment',
        );
    }
}
