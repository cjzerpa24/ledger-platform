<?php

declare(strict_types=1);

namespace App\Domain\Account;

class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'UUID')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $customerId;
    
    #[ORM\Column(type: 'string')]
    private string $name;

    #[ORM\Column(type: 'string')]
    private string $description;

    #[ORM\Column(type: 'string')]
    private string $accountNumber;

    #[ORM\Column(type: 'string')]
    private AccountType $accountType;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $accountBalance;

    #[ORM\Column(type: 'string')]
    private AccountStatus $accountStatus;

    #[ORM\Column(type: 'datetime')]
    private DateTime $createdAt;

    #[ORM\Column(type: 'datetime')]
    private ?DateTime $updatedAt = null;

    public function __construct() {
        $this->createdAt = new DateTime();
        $this->accountStatus = AccountStatus::ACTIVE;
        $this->accountBalance = 0;
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

    public function getCustomerId(): Uuid
    {
        return $this->customerId;
    }

    public function setCustomerId(Uuid $customerId): self
    {
        $this->customerId = $customerId;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    public function setAccountNumber(string $accountNumber): self
    {
        $this->accountNumber = $accountNumber;
        return $this;
    }

    public function getAccountType(): string
    {
        return $this->accountType;
    }

    public function setAccountType(string $accountType): self
    {
        $this->accountType = $accountType;
        return $this;
    }

    public function getAccountBalance(): string
    {
        return $this->accountBalance;
    }

    public function setAccountBalance(string $accountBalance): self
    {
        $this->accountBalance = $accountBalance;
        return $this;
    }

    public function getAccountStatus(): string
    {
        return $this->accountStatus;
    }

    public function setAccountStatus(string $accountStatus): self
    {
        $this->accountStatus = $accountStatus;
        return $this;
    }

    public function debit(float $amount): self
    {
        $this->accountBalance -= $amount;
        return $this;
    }

    public function credit(float $amount): self
    {
        $this->accountBalance += $amount;
        return $this;
    }
}
