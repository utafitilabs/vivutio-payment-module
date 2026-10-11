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

namespace Vivutio\Payment\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Vivutio\Payment\Enum\MethodEnum;
use Vivutio\Payment\Repository\RecordedPaymentRepository;

/**
 * A payment received against a charge, recorded as it came: the charge's
 * key and reference, the amount in cents of its currency, the day received,
 * how and the payer's reference, who recorded it. Never deleted: struck out,
 * with why and by whom.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: RecordedPaymentRepository::class)]
#[ORM\Table(name: 'payment_recorded')]
#[ORM\Index(name: 'payment_recorded_charge', columns: ['charge_key'])]
class RecordedPayment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 120)]
    private string $chargeKey;

    /** The charge's reference when it was paid, "NCS-0002". */
    #[ORM\Column(length: 40)]
    private string $chargeReference;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** In cents. */
    #[ORM\Column(type: Types::BIGINT)]
    private string $amount;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $receivedOn;

    #[ORM\Column(length: 16, enumType: MethodEnum::class)]
    private MethodEnum $method;

    #[ORM\Column(length: 60)]
    private string $reference = '';

    #[ORM\Column(length: 200)]
    private string $note = '';

    #[ORM\Column(length: 120)]
    private string $recordedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $struckReason = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $struckBy = null;

    public function __construct(string $chargeKey, string $chargeReference, string $currency, int $amount, \DateTimeImmutable $receivedOn, MethodEnum $method, string $recordedBy, \DateTimeImmutable $recordedAt)
    {
        $this->uuid = Uuid::v7();
        $this->chargeKey = $chargeKey;
        $this->chargeReference = $chargeReference;
        $this->currency = $currency;
        $this->amount = (string) $amount;
        $this->receivedOn = $receivedOn;
        $this->method = $method;
        $this->recordedBy = $recordedBy;
        $this->recordedAt = $recordedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getChargeKey(): string
    {
        return $this->chargeKey;
    }

    public function getChargeReference(): string
    {
        return $this->chargeReference;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getAmount(): int
    {
        return (int) $this->amount;
    }

    public function getReceivedOn(): \DateTimeImmutable
    {
        return $this->receivedOn;
    }

    public function getMethod(): MethodEnum
    {
        return $this->method;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function setNote(string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getRecordedBy(): string
    {
        return $this->recordedBy;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function isStruck(): bool
    {
        return null !== $this->struckReason;
    }

    public function getStruckReason(): ?string
    {
        return $this->struckReason;
    }

    public function getStruckBy(): ?string
    {
        return $this->struckBy;
    }

    public function strike(string $reason, string $by): static
    {
        $this->struckReason = $reason;
        $this->struckBy = $by;

        return $this;
    }
}
