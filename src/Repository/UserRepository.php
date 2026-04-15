<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByAmemberUserId(string $amemberUserId): ?User
    {
        return $this->findOneBy(['amemberUserId' => $amemberUserId]);
    }

    public function getOrCreate(string $amemberUserId): User
    {
        $existing = $this->findOneByAmemberUserId($amemberUserId);
        if ($existing !== null) {
            return $existing;
        }
        $user = new User();
        $user->setAmemberUserId($amemberUserId);
        $em = $this->getEntityManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
