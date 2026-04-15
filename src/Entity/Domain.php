<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'domains')]
#[ORM\UniqueConstraint(name: 'domains_fqdn_unique', columns: ['domain_fqdn'])]
class Domain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $domainFqdn = '';

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'domains')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: RegistryContact::class, inversedBy: 'domains')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?RegistryContact $registryContact = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomainFqdn(): string
    {
        return $this->domainFqdn;
    }

    public function setDomainFqdn(string $domainFqdn): self
    {
        $this->domainFqdn = $domainFqdn;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getRegistryContact(): ?RegistryContact
    {
        return $this->registryContact;
    }

    public function setRegistryContact(?RegistryContact $registryContact): self
    {
        $this->registryContact = $registryContact;

        return $this;
    }
}
