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

use Psr\Clock\ClockInterface;
use Vivutio\Contracts\Partner\Charge;
use Vivutio\Contracts\Shell\DashboardCard;
use Vivutio\Contracts\Shell\DashboardFigure;
use Vivutio\Contracts\Shell\DashboardRow;
use Vivutio\Contracts\Shell\DashboardSourceInterface;
use Vivutio\Payment\Controller\PaymentController;
use Vivutio\Payment\Service\PaymentService;

/**
 * Payments on the organization's dashboard, as designed in vivutio-designs
 * shell/dashboard.html: what is owed to the organization in the band, with
 * how much of it is late, and the overdue charges, the longest late first.
 * Owed in several currencies, the late part is counted in charges, since
 * the amounts would not fit beside each other.
 */
final readonly class PaymentDashboard implements DashboardSourceInterface
{
    public function __construct(
        private PaymentService $payments,
        private ClockInterface $clock,
    ) {
    }

    public function figures(): iterable
    {
        $all = $this->payments->standing();
        $owed = PaymentService::owed($all);
        if ([] === $owed) {
            return;
        }
        $overdue = $this->overdue($all);
        $qualifier = match (true) {
            [] === $overdue => '',
            \count($owed) > 1 => \count($overdue).' overdue',
            default => PaymentService::amounts(PaymentService::owed($overdue), '').' overdue',
        };

        yield new DashboardFigure('Owed to you', PaymentService::amounts($owed, ''), $qualifier, PaymentController::READ, 90);
    }

    public function cards(): iterable
    {
        $overdue = $this->overdue($this->payments->standing());
        $today = $this->clock->now()->setTime(0, 0);
        $rows = array_map(static function (array $row) use ($today): DashboardRow {
            $late = (int) $row['charge']->dueOn->setTime(0, 0)->diff($today)->days;

            return new DashboardRow(
                $row['charge']->reference,
                $row['who'],
                [PaymentService::money($row['charge']->currency, $row['owed'])],
                $late.' '.(1 === $late ? 'day' : 'days').' late',
                'danger',
            );
        }, \array_slice($overdue, 0, DashboardCard::MOST_ROWS));

        yield new DashboardCard(
            'Overdue',
            'Payments',
            'the longest late first',
            $rows,
            \count($overdue),
            'Nothing is overdue',
            'All payments',
            PaymentController::REGISTER,
            PaymentController::READ,
            ['state' => PaymentService::OVERDUE],
            40,
        );
    }

    /**
     * The overdue rows, the longest late first.
     *
     * @param list<array{charge: Charge, paid: int, owed: int, state: string, who: string}> $all
     *
     * @return list<array{charge: Charge, paid: int, owed: int, state: string, who: string}>
     */
    private function overdue(array $all): array
    {
        $overdue = array_values(array_filter($all, static fn (array $row): bool => PaymentService::OVERDUE === $row['state']));
        usort($overdue, static fn (array $a, array $b): int => [$a['charge']->dueOn, $a['charge']->reference] <=> [$b['charge']->dueOn, $b['charge']->reference]);

        return $overdue;
    }
}
