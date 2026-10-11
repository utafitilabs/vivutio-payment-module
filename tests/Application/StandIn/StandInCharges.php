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

namespace Vivutio\Payment\Tests\Application\StandIn;

use Vivutio\Contracts\Partner\Charge;
use Vivutio\Contracts\Partner\ChargeSourceInterface;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;

/**
 * A package that charges, played for the module's suite: two tour bookings,
 * one through a partner traded with, one a party paying directly, already
 * late.
 */
final readonly class StandInCharges implements ChargeSourceInterface
{
    public function __construct(private PartnerDirectoryInterface $partners)
    {
    }

    public function charges(): iterable
    {
        foreach ($this->partners->active() as $partner) {
            if ('Savanna Trails Travel' === $partner->getName()) {
                yield new Charge('stand_in:ncs-2', 'NCS-0002', 'Tour booking', 'Northern Circuit Safari, 2 – 7 Aug 2027', $partner->getPartnerId(), $partner->getName(), 'USD', 760858, new \DateTimeImmutable('2026-10-06'), new \DateTimeImmutable('2026-11-05'), 'shell_dashboard');
            }
        }
        yield new Charge('stand_in:ncs-4', 'NCS-0004', 'Tour booking', 'Northern Circuit Safari, 18 – 23 Oct 2026', null, 'Kweka party', 'USD', 391220, new \DateTimeImmutable('2026-10-02'), new \DateTimeImmutable('2026-10-10'));
    }
}
