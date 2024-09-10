<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\Persistence\ManagerRegistry;
use MonextSyliusPlugin\Entity\MonextReference;

/**
 * @extends ServiceEntityRepository<MonextReference>
 */
class MonextReferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonextReference::class);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function findOneByToken(string $token): ?MonextReference
    {
        if ('' === $token) {
            return null;
        }

        return $this->createQueryBuilder('m')
            ->andWhere('m.token = :val')
            ->setParameter('val', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @throws NonUniqueResultException
     */
    public function findOneByPaymentId(int $paymentId): ?MonextReference
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.payment = :val')
            ->setParameter('val', $paymentId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
