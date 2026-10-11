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

namespace Vivutio\Payment\Enum;

/**
 * How a payment came.
 */
enum MethodEnum: string
{
    case BankTransfer = 'bank_transfer';
    case MobileMoney = 'mobile_money';
    case Cash = 'cash';
    case Card = 'card';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::MobileMoney => 'Mobile money',
            self::Cash => 'Cash',
            self::Card => 'Card',
            self::Cheque => 'Cheque',
        };
    }
}
