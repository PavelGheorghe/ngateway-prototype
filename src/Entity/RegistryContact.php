<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RegistryContactRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RegistryContactRepository::class)]
#[ORM\Table(name: 'registry_contacts')]
#[ORM\UniqueConstraint(name: 'registry_contacts_user_registry', columns: ['user_id', 'registry_id'])]
class RegistryContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $registryId = '';

    #[ORM\Column(length: 16)]
    private string $contactId = '';

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'registryContacts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\OneToMany(targetEntity: Domain::class, mappedBy: 'registryContact', cascade: ['persist'])]
    private Collection $domains;

    public function __construct()
    {
        $this->domains = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRegistryId(): string
    {
        return $this->registryId;
    }

    public function setRegistryId(string $registryId): self
    {
        $this->registryId = $registryId;

        return $this;
    }

    public function getContactId(): string
    {
        return $this->contactId;
    }

    public function setContactId(string $contactId): self
    {
        $this->contactId = $contactId;

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

    /** @return Collection<int, Domain> */
    public function getDomains(): Collection
    {
        return $this->domains;
    }

    public function addDomain(Domain $domain): self
    {
        if (!$this->domains->contains($domain)) {
            $this->domains->add($domain);
            $domain->setRegistryContact($this);
        }

        return $this;
    }
}
