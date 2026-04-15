<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'users_amember_user_id_unique', columns: ['amember_user_id'])]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $amemberUserId = '';

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_USER'];

    #[ORM\OneToMany(targetEntity: RegistryContact::class, mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    private Collection $registryContacts;

    #[ORM\OneToMany(targetEntity: Domain::class, mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    private Collection $domains;

    #[ORM\OneToOne(targetEntity: UserContact::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?UserContact $userContact = null;

    public function __construct()
    {
        $this->registryContacts = new ArrayCollection();
        $this->domains = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAmemberUserId(): string
    {
        return $this->amemberUserId;
    }

    public function setAmemberUserId(string $amemberUserId): self
    {
        $this->amemberUserId = $amemberUserId;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->amemberUserId;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        if (!\in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    /** @return Collection<int, RegistryContact> */
    public function getRegistryContacts(): Collection
    {
        return $this->registryContacts;
    }

    public function addRegistryContact(RegistryContact $registryContact): self
    {
        if (!$this->registryContacts->contains($registryContact)) {
            $this->registryContacts->add($registryContact);
            $registryContact->setUser($this);
        }

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
            $domain->setUser($this);
        }

        return $this;
    }

    public function getUserContact(): ?UserContact
    {
        return $this->userContact;
    }

    public function setUserContact(?UserContact $userContact): self
    {
        $this->userContact = $userContact;

        return $this;
    }
}
