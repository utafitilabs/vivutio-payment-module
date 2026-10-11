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

namespace Vivutio\Payment\Partner;

use Vivutio\Contracts\Partner\Payment;
use Vivutio\Contracts\Partner\PaymentSourceInterface;
use Vivutio\Payment\Entity\RecordedPayment;
use Vivutio\Payment\Repository\RecordedPaymentRepository;

/**
 * The payments recorded here, said to the core for whoever keeps the books;
 * one struck out is not said.
 */
final readonly class RecordedPayments implements PaymentSourceInterface
{
    public function __construct(private RecordedPaymentRepository $payments)
    {
    }

    public function payments(): iterable
    {
        foreach ($this->payments->findBy([], ['receivedOn' => 'ASC', 'id' => 'ASC']) as $payment) {
            if (!$payment->isStruck()) {
                yield self::said($payment);
            }
        }
    }

    private static function said(RecordedPayment $payment): Payment
    {
        return new Payment('payment:'.$payment->getUuid(), $payment->getChargeKey(), $payment->getCurrency(), $payment->getAmount(), $payment->getReceivedOn(), $payment->getMethod()->label(), $payment->getReference());
    }
}
