# vivutio/payment-module

**Payments for vivutio.** What the organization is owed for its bookings and
tickets, gathered by the core from every module that charges, and the
payments received against it, recorded by hand as they came.

## Contents

- [Install](#install)
- [How it works](#how-it-works)
- [Who may do what](#who-may-do-what)
- [Development](#development)
- [Licence](#licence)

## Install

In a vivutio installation:

```bash
composer require vivutio/payment-module
php bin/console doctrine:migrations:migrate
```

Its recipe registers the bundle and mounts its pages with
`config/routes/payment.yaml`, a file the installation owns.

## How it works

A module that charges for something (a tour booking, a ticket) says so to the
core through `ChargeSourceInterface`: a reference, who owes it (a partner, or
a party paying directly), the amount and currency, when it was raised and
when it falls due. This module reads every charge through the core's
`ChargesInterface` and names no module.

**Payments** shows what is owed, paid and overdue: a row a charge, with what
it comes to, what has been paid, what is owed, and where it stands (unpaid,
part paid, paid, overdue). A charge's page lists its payments and records one
as it came: the amount (never more than is owed), the day received, how (bank
transfer, mobile money, cash, card, cheque) and the payer's reference. A
payment recorded wrongly is struck out with why, never deleted. A charge of
nothing (a ticket voided, a hold let go at no charge) is not listed unless
something was paid against it, which is money held; its page still opens.

On the organization's dashboard, through the core's `DashboardSourceInterface`,
the module puts **Owed to you** in the band (what is owed, by currency, and how
much of it is overdue, or how many charges when several currencies are owed)
and an **Overdue** card: the overdue charges, the longest late first, five at
most, each with what is owed and how many days late, and a door to the
register's overdue. Both are for whoever holds `payments.read`.

The payments are said back to the core through `PaymentSourceInterface`, for
whoever keeps the books. Taking payments through a provider (DPO Pay, Pesapal,
Selcom, Stripe) comes later.

## Who may do what

| Pair | Who |
|---|---|
| `payments.read` | The register, a charge's page, and payments on the dashboard |
| `payments.record` | Recording a payment |
| `payments.manage` | Striking a payment out |

Each is a module pair, held where the person's department allows it too. The
suite extends the core's authority test base; `tests/authority-table.md` is
the reviewed table.

## Development

```bash
composer update
composer check
```

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).
