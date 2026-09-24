<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

class Transfer
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'UUID')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Account $fromAccountId;

    #[ORM\Column(type: 'uuid')]
    private Account $toAccountId;

    #[ORM\Column(type: 'datetime')]
    private DateTime $transferDate;

    #[ORM\Column(type: 'string')]
    private TransferType $transferType;

    #[ORM\Column(type: 'string')]
    private ?string $reference = null;

    #[ORM\Column(type: 'string')]
    private ?string $description = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $amount;

    #[ORM\Column(type: 'string')]
    private TransferStatus $status;

    #[ORM\Column(type: 'datetime')]
    private DateTime $createdAt;

    #[ORM\Column(type: 'datetime')]
    private ?DateTime $updatedAt = null;

    public function __construct() {
        $this->createdAt = new DateTime();
    }
    
    public function getId(): Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getFromAccountId(): Account
    {
        return $this->fromAccountId;
    }

    public function setFromAccountId(Account $fromAccountId): self
    {
        $this->fromAccountId = $fromAccountId;
        return $this;
    }

    public function getToAccountId(): Account
    {
        return $this->toAccountId;
    }

    public function setToAccountId(Account $toAccountId): self
    {
        $this->toAccountId = $toAccountId;
        return $this;
    }

    public function getTransferDate(): DateTime
    {
        return $this->transferDate;
    }

    public function setTransferDate(DateTime $transferDate): self
    {
        $this->transferDate = $transferDate;
        return $this;
    }

    public function getTransactionType(): TransactionType
    {
        return $this->transactionType;
    }

    public function setTransactionType(TransactionType $transactionType): self
    {
        $this->transactionType = $transactionType;
        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getStatus(): TransferStatus
    {
        return $this->status;
    }

    public function setStatus(TransferStatus $status): self
    {
        $this->status = $status;
        return $this;
    }
}
