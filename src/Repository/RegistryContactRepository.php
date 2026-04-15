<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RegistryContact;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegistryContact>
 */
final class RegistryContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistryContact::class);
    }

    public function findOneByUserAndRegistry(User $user, string $registryId): ?RegistryContact
    {
        return $this->findOneBy(['user' => $user, 'registryId' => $registryId]);
    }

    /**
     * Brizy embed: resolve stored CORE contact handle for an amember user + registry (TLD).
     */
    public function findContactIdForAmemberAndRegistry(string $amemberUserId, string $registryId): ?string
    {
        $amemberUserId = trim($amemberUserId);
        if ($amemberUserId === '') {
            return null;
        }
        $registryId = trim($registryId);
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }
        if ($registryId === '') {
            return null;
        }

        $rc = $this->createQueryBuilder('rc')
            ->join('rc.user', 'u')
            ->where('u.amemberUserId = :amid')
            ->andWhere('rc.registryId = :reg')
            ->setParameter('amid', $amemberUserId)
            ->setParameter('reg', $registryId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$rc instanceof RegistryContact) {
            return null;
        }

        $id = trim($rc->getContactId());

        return $id !== '' ? $id : null;
    }
}
