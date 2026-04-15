<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Domain;
use App\Entity\RegistryContact;
use App\Entity\User;
use App\Entity\UserContact;
use App\Repository\RegistryContactRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists Brizy/amember-linked users, registry contact handles, profiles, and owned domains to the local DB.
 */
final class CustomerPersistenceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly RegistryContactRepository $registryContactRepository,
    ) {
    }

    /**
     * After successful CORE contact.create — requires `amemberUserId` on the API payload.
     *
     * @param array<string, mixed> $input   Original request body (contact.create shape + amemberUserId)
     * @param array<string, mixed> $response Normalized JSON from ContactService::create
     */
    public function persistAfterContactCreate(array $input, array $response): void
    {
        $amember = trim((string) ($input['amemberUserId'] ?? ''));
        if ($amember === '') {
            return;
        }
        if (!($response['success'] ?? false) || empty($response['contactId'])) {
            return;
        }
        $registryId = (string) ($input['registryId'] ?? '.com');
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }
        $contactId = trim((string) $response['contactId']);
        $contact = $input['contact'] ?? [];
        if (!is_array($contact)) {
            $contact = [];
        }

        $user = $this->userRepository->getOrCreate($amember);
        $this->upsertUserContactFromContactPayload($user, $contact);
        $this->upsertRegistryContact($user, $registryId, $contactId);
        $this->em->flush();
    }

    /**
     * After successful Brizy domain purchase (hosted zone + DNS + domain.create).
     */
    public function persistAfterDomainPurchase(string $amemberUserId, string $domainFqdn, string $registryId, string $contactId): void
    {
        $amemberUserId = trim($amemberUserId);
        if ($amemberUserId === '') {
            return;
        }
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }
        $domainFqdn = strtolower(trim($domainFqdn));
        $contactId = trim($contactId);
        if ($domainFqdn === '' || $contactId === '') {
            return;
        }

        $user = $this->userRepository->getOrCreate($amemberUserId);
        $registryContact = $this->upsertRegistryContact($user, $registryId, $contactId);

        $existing = $this->em->getRepository(Domain::class)->findOneBy(['domainFqdn' => $domainFqdn]);
        if ($existing !== null) {
            $this->em->flush();

            return;
        }

        $domain = new Domain();
        $domain->setDomainFqdn($domainFqdn);
        $domain->setUser($user);
        $domain->setRegistryContact($registryContact);
        $this->em->persist($domain);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $contact
     */
    private function upsertUserContactFromContactPayload(User $user, array $contact): void
    {
        $name = trim((string) ($contact['name'] ?? ''));
        $email = trim((string) ($contact['email'] ?? ''));
        if ($name === '' && $email === '') {
            return;
        }

        $uc = $user->getUserContact();
        if ($uc === null) {
            $uc = new UserContact();
            $uc->setUser($user);
            $user->setUserContact($uc);
            $this->em->persist($uc);
        }

        if ($name !== '') {
            $uc->setName($name);
        }
        if ($email !== '') {
            $uc->setEmail($email);
        }
        $phone = trim((string) ($contact['phone'] ?? $contact['voice'] ?? ''));
        $uc->setPhone($phone !== '' ? $phone : null);
        $org = trim((string) ($contact['organization'] ?? ''));
        $uc->setOrganization($org !== '' ? $org : null);
        $street = trim((string) ($contact['address'] ?? $contact['street'] ?? ''));
        $uc->setStreet($street !== '' ? $street : null);
        $city = trim((string) ($contact['city'] ?? ''));
        $uc->setCity($city !== '' ? $city : null);
        $state = trim((string) ($contact['state'] ?? ''));
        $uc->setState($state !== '' ? $state : null);
        $postal = trim((string) ($contact['postalcode'] ?? $contact['postalCode'] ?? ''));
        $uc->setPostalCode($postal !== '' ? $postal : null);
        $cc = strtoupper(trim((string) ($contact['countrycode'] ?? $contact['countryCode'] ?? '')));
        if ($cc !== '') {
            $uc->setCountryCode(substr($cc, 0, 2));
        }
    }

    private function upsertRegistryContact(User $user, string $registryId, string $contactId): RegistryContact
    {
        $existing = $this->registryContactRepository->findOneByUserAndRegistry($user, $registryId);
        if ($existing !== null) {
            $existing->setContactId($contactId);

            return $existing;
        }
        $rc = new RegistryContact();
        $rc->setUser($user);
        $rc->setRegistryId($registryId);
        $rc->setContactId($contactId);
        $this->em->persist($rc);

        return $rc;
    }
}
