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

namespace Vivutio\Payment\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Vivutio\Contracts\Partner\Charge;
use Vivutio\Contracts\Partner\ChargesInterface;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Contracts\Partner\PartnerInterface;
use Vivutio\Payment\Entity\RecordedPayment;
use Vivutio\Payment\Enum\MethodEnum;
use Vivutio\Payment\Exception\InvalidPaymentException;
use Vivutio\Payment\Repository\RecordedPaymentRepository;

/**
 * What the organization is owed, from every module that charges, through the
 * core, and the payments received against it, recorded by hand as they came:
 * never more than is owed, never deleted, struck out with why. A charge is
 * paid when nothing is owed on it, overdue when something is owed after its
 * day.
 */
final readonly class PaymentService
{
    public const string PAID = 'paid';
    public const string PART = 'part';
    public const string UNPAID = 'unpaid';
    public const string OVERDUE = 'overdue';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private ChargesInterface $charges,
        private PartnerDirectoryInterface $partners,
        private RecordedPaymentRepository $payments,
    ) {
    }

    /**
     * Every charge with what is paid and owed on it, the soonest due first.
     *
     * @return list<array{charge: Charge, paid: int, owed: int, state: string, who: string}>
     */
    public function standing(): array
    {
        $paid = [];
        foreach ($this->payments->findAll() as $payment) {
            if (!$payment->isStruck()) {
                $paid[$payment->getChargeKey()] = ($paid[$payment->getChargeKey()] ?? 0) + $payment->getAmount();
            }
        }

        return array_map(fn (Charge $charge): array => $this->row($charge, $paid[$charge->key] ?? 0), $this->charges->all());
    }

    /**
     * One charge with what is paid and owed on it, or null where no module charges it.
     *
     * @return array{charge: Charge, paid: int, owed: int, state: string, who: string}|null
     */
    public function one(string $key): ?array
    {
        $charge = $this->charges->find($key);
        if (null === $charge) {
            return null;
        }
        $paid = array_sum(array_map(static fn (RecordedPayment $payment): int => $payment->isStruck() ? 0 : $payment->getAmount(), $this->paymentsOn($key)));

        return $this->row($charge, $paid);
    }

    /**
     * @return list<RecordedPayment> the latest received first
     */
    public function paymentsOn(string $key): array
    {
        return $this->payments->findBy(['chargeKey' => $key], ['receivedOn' => 'DESC', 'id' => 'DESC']);
    }

    public function partnerOf(Charge $charge): ?PartnerInterface
    {
        return null === $charge->partnerId ? null : $this->partners->find($charge->partnerId);
    }

    /**
     * What came in this month, by currency.
     *
     * @return array{amounts: array<string, int>, count: int}
     */
    public function paidThisMonth(): array
    {
        $month = $this->clock->now()->format('Y-m');
        $amounts = [];
        $count = 0;
        foreach ($this->payments->findAll() as $payment) {
            if (!$payment->isStruck() && $payment->getReceivedOn()->format('Y-m') === $month) {
                $amounts[$payment->getCurrency()] = ($amounts[$payment->getCurrency()] ?? 0) + $payment->getAmount();
                ++$count;
            }
        }

        return ['amounts' => $amounts, 'count' => $count];
    }

    /**
     * @param array<mixed> $sent amount, received, method, reference, note
     *
     * @throws InvalidPaymentException
     */
    public function record(string $key, array $sent, string $by): RecordedPayment
    {
        $standing = $this->one($key) ?? throw new InvalidPaymentException('amount', 'Nothing is charged under this key.');
        $charge = $standing['charge'];
        $text = static fn (string $field): string => \is_string($sent[$field] ?? null) ? trim($sent[$field]) : '';

        $typed = str_replace(',', '', $text('amount'));
        if (1 !== preg_match('{^\d{1,9}(\.\d{1,2})?$}D', $typed) || 0 === self::cents($typed)) {
            throw new InvalidPaymentException('amount', 'An amount to the cent: 3,000.00.');
        }
        $amount = self::cents($typed);
        if ($amount > $standing['owed']) {
            throw new InvalidPaymentException('amount', \sprintf('More than is owed, %s: an overpayment is a credit, kept in the books.', self::money($charge->currency, $standing['owed'])));
        }
        $received = \DateTimeImmutable::createFromFormat('!Y-m-d', $text('received'));
        if (false === $received || $received->format('Y-m-d') !== $text('received') || $received > $this->clock->now()->setTime(0, 0)) {
            throw new InvalidPaymentException('received', 'A day up to today, as it was received.');
        }
        $method = MethodEnum::tryFrom($text('method')) ?? throw new InvalidPaymentException('method', 'Bank transfer, mobile money, cash, card or cheque.');
        if (mb_strlen($text('reference')) > 60) {
            throw new InvalidPaymentException('reference', 'A reference is at most 60 characters.');
        }
        if (mb_strlen($text('note')) > 200) {
            throw new InvalidPaymentException('note', 'A note is at most 200 characters.');
        }

        $payment = (new RecordedPayment($charge->key, $charge->reference, $charge->currency, $amount, $received, $method, $by, $this->clock->now()))
            ->setReference($text('reference'))
            ->setNote($text('note'));
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $payment;
    }

    /**
     * @throws InvalidPaymentException
     */
    public function strike(RecordedPayment $payment, string $reason, string $by): void
    {
        $reason = trim($reason);
        if ($payment->isStruck()) {
            throw new InvalidPaymentException('which', 'That payment is struck out already.');
        }
        if ('' === $reason || mb_strlen($reason) > 200) {
            throw new InvalidPaymentException('reason', 'Say why, in at most 200 characters.');
        }
        $payment->strike($reason, $by);
        $this->entityManager->flush();
    }

    /** "USD 4,608.58", an amount in cents of a currency. */
    public static function money(string $currency, int $cents): string
    {
        return $currency.' '.number_format($cents / 100, 2);
    }

    /**
     * Amounts in several currencies, one after the other: "USD 1.00 · TZS 25,000.00".
     *
     * @param array<string, int> $amounts
     */
    public static function amounts(array $amounts, string $none): string
    {
        if ([] === $amounts) {
            return $none;
        }
        ksort($amounts);

        return implode(' · ', array_map(static fn (string $currency, int $cents): string => self::money($currency, $cents), array_keys($amounts), array_values($amounts)));
    }

    private static function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * @return array{charge: Charge, paid: int, owed: int, state: string, who: string}
     */
    private function row(Charge $charge, int $paid): array
    {
        $owed = max(0, $charge->amount - $paid);
        $state = match (true) {
            0 === $owed => self::PAID,
            $charge->dueOn < $this->clock->now()->setTime(0, 0) => self::OVERDUE,
            $paid > 0 => self::PART,
            default => self::UNPAID,
        };

        return ['charge' => $charge, 'paid' => $paid, 'owed' => $owed, 'state' => $state, 'who' => $this->partnerOf($charge)?->getName() ?? $charge->party];
    }
}
