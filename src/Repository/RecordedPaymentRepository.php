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

namespace Vivutio\Payment\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Vivutio\Payment\Entity\RecordedPayment;

/**
 * @extends ServiceEntityRepository<RecordedPayment>
 */
class RecordedPaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecordedPayment::class);
    }
}
