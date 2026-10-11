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

namespace Vivutio\Payment\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Vivutio\Bundle\IdentityBundle\Test\AuthorityTestCase;
use Vivutio\Bundle\IdentityBundle\Test\Probe;
use Vivutio\Payment\Controller\PaymentController;
use Vivutio\Payment\Entity\RecordedPayment;
use Vivutio\Payment\Enum\MethodEnum;
use Vivutio\Payment\Tests\Application\Kernel;

/**
 * The module held to the core's five proofs, through the base every module's
 * suite extends. Record a reviewed change to the table with:
 *
 *     VIVUTIO_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit --filter PaymentAuthorityTest
 */
final class PaymentAuthorityTest extends AuthorityTestCase
{
    private const string PAYMENT_UUID = '0199b1c0-0000-7000-8000-00000000e001';
    private const string CHARGE = '/payments/stand_in:ncs-4';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function probes(): array
    {
        return [
            new Probe(PaymentController::REGISTER, 'GET', '/payments'),
            new Probe(PaymentController::SHOW, 'GET', self::CHARGE),
            new Probe(PaymentController::RECORD_PAYMENT, 'POST', self::CHARGE.'/record', ['amount' => '1.00', 'received' => '2026-10-01', 'method' => 'cash', 'reference' => '', 'note' => ''], formAt: self::CHARGE),
            // The first allowed strikes it out; the next finds it struck out already.
            new Probe(PaymentController::STRIKE, 'POST', self::CHARGE.'/strike', ['which' => self::PAYMENT_UUID, 'reason' => 'Probed'], formAt: self::CHARGE),
        ];
    }

    protected static function packageDirectory(): string
    {
        return \dirname(__DIR__);
    }

    protected static function authorityTable(): string
    {
        return __DIR__.'/authority-table.md';
    }

    protected function seedSubjects(EntityManagerInterface $entityManager): void
    {
        $entityManager->persist((new RecordedPayment('stand_in:ncs-4', 'NCS-0004', 'USD', 10000, new \DateTimeImmutable('2026-10-01'), MethodEnum::Cash, 'Probed', new \DateTimeImmutable('2026-10-01')))->setUuid(Uuid::fromString(self::PAYMENT_UUID)));
    }
}
