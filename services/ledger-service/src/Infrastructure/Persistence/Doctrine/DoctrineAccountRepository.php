<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Account\AccountType;
use App\Domain\Account\Exception\AccountNotFound;
use App\Domain\Account\Exception\SettlementAccountMissing;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Money\Currency;
use Symfony\Component\Uid\Uuid;

final class DoctrineAccountRepository implements AccountRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function add(Account $account): void
    {
        $this->entityManager->persist($account);
    }

    public function get(Uuid $id): Account
    {
        return $this->entityManager->find(Account::class, $id) ?? throw AccountNotFound::withId($id);
    }

    public function getForUpdate(Uuid $id): Account
    {
        $account = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Account::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            // An earlier non-locking read may have cached a stale balance.
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $account instanceof Account ? $account : throw AccountNotFound::withId($id);
    }

    public function getSettlement(Currency $currency): Account
    {
        $account = $this->entityManager->getRepository(Account::class)->findOneBy([
            'type' => AccountType::SETTLEMENT,
            'currency' => $currency->getCode(),
        ]);

        return $account ?? throw SettlementAccountMissing::forCurrency($currency);
    }
}
