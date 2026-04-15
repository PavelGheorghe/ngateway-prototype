<?php

declare(strict_types=1);

namespace App\Service;

class ContactService
{
    public function __construct(
        private readonly CoreGatewayClient $client,
        private readonly CoreGatewayHelper $helper,
        private readonly string $providerChainSpec,
        private readonly string $providerChainType,
        private readonly bool $skipProviderChain,
    ) {
    }

    public function create(array $input): array
    {
        $registryId = (string) ($input['registryId'] ?? '.com');
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }

        $contact = $input['contact'] ?? [];
        if (!is_array($contact) || $contact === []) {
            return ['success' => false, 'code' => 'invalid', 'message' => 'contact object required'];
        }

        $name = trim((string) ($contact['name'] ?? ''));
        $email = trim((string) ($contact['email'] ?? ''));
        if ($name === '' || $email === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'contact.name and contact.email are required'];
        }

        $contactId = $this->helper->normalizeContactId((string) ($input['contactId'] ?? $contact['id'] ?? ''));
        $countrycode = strtoupper(trim((string) ($contact['countrycode'] ?? $contact['countryCode'] ?? 'US')));
        $street = trim((string) ($contact['address'] ?? $contact['street'] ?? '')) ?: 'N/A';
        $city = trim((string) ($contact['city'] ?? '')) ?: 'N/A';
        $postalcode = trim((string) ($contact['postalcode'] ?? $contact['postalCode'] ?? '')) ?: 'N/A';
        $state = trim((string) ($contact['state'] ?? '')) ?: 'N/A';
        $phone = $this->normalizePhone((string) ($contact['phone'] ?? $contact['voice'] ?? ''));
        $organization = trim((string) ($contact['organization'] ?? '')) ?: 'N/A';

        $payload = $this->helper->filterEmpty([
            'contact.id' => $contactId,
            'contact.name' => $name,
            'contact.organization' => $organization,
            'contact.address.street.1' => $street,
            'contact.address.city' => $city,
            'contact.address.state' => $state,
            'contact.address.postalcode' => $postalcode,
            'contact.address.countrycode' => $countrycode,
            'contact.voice.number' => $phone,
            'contact.email' => $email,
        ]);
        $payload = $this->helper->addProviderChain($payload, $this->providerChainSpec, $this->providerChainType, $this->skipProviderChain);

        $result = $this->helper->coreToJson($this->client->contactCreate($registryId, $payload));
        if (($result['success'] ?? false) === true) {
            $result['contactId'] = $contactId;
        }

        return $result;
    }

    private function normalizePhone(string $phone): string
    {
        $normalized = trim(preg_replace('/\s+/', '', $phone) ?? '');
        if ($normalized === '') {
            return '+1.0000000000';
        }

        // CORE rejects many non-prefixed values; convert 00CC... to +CC...
        if (str_starts_with($normalized, '00')) {
            return '+' . substr($normalized, 2);
        }

        // If user enters country code without plus, normalize to + form.
        if ($normalized[0] !== '+' && ctype_digit($normalized[0])) {
            return '+' . $normalized;
        }

        return $normalized;
    }

    public function list(array $input = []): array
    {
        return [
            'success' => false,
            'code' => '20101',
            'message' => 'contact.list is not a valid request.type in CORE Payload 2.0 (bundled spec). Use a known contact.id with contact.inquire instead — see /client-workbench.',
            'data' => [],
        ];
    }

    public function inquire(array $input): array
    {
        $registryId = (string) ($input['registryId'] ?? '.com');
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }
        $contactId = trim((string) ($input['contactId'] ?? ''));
        if ($contactId === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'contactId required'];
        }

        return $this->helper->coreToJson($this->client->contactInquire($registryId, $contactId));
    }

    public function modify(array $input): array
    {
        $registryId = (string) ($input['registryId'] ?? '.com');
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }
        $contactId = trim((string) ($input['contactId'] ?? ''));
        if ($contactId === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'contactId required'];
        }

        $contact = $input['contact'] ?? [];
        if (!is_array($contact) || $contact === []) {
            return ['success' => false, 'code' => 'invalid', 'message' => 'contact object required'];
        }

        $name = trim((string) ($contact['name'] ?? ''));
        $email = trim((string) ($contact['email'] ?? ''));
        if ($name === '' || $email === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'contact.name and contact.email are required'];
        }

        $countrycode = strtoupper(trim((string) ($contact['countrycode'] ?? $contact['countryCode'] ?? 'US')));
        $street = trim((string) ($contact['address'] ?? $contact['street'] ?? '')) ?: 'N/A';
        $city = trim((string) ($contact['city'] ?? '')) ?: 'N/A';
        $postalcode = trim((string) ($contact['postalcode'] ?? $contact['postalCode'] ?? '')) ?: 'N/A';
        $state = trim((string) ($contact['state'] ?? '')) ?: 'N/A';
        $phone = $this->normalizePhone((string) ($contact['phone'] ?? $contact['voice'] ?? ''));
        $organization = trim((string) ($contact['organization'] ?? '')) ?: 'N/A';

        $payload = $this->helper->filterEmpty([
            'contact.name' => $name,
            'contact.organization' => $organization,
            'contact.address.street.1' => $street,
            'contact.address.city' => $city,
            'contact.address.state' => $state,
            'contact.address.postalcode' => $postalcode,
            'contact.address.countrycode' => $countrycode,
            'contact.voice.number' => $phone,
            'contact.email' => $email,
        ]);
        $payload = $this->helper->addProviderChain($payload, $this->providerChainSpec, $this->providerChainType, $this->skipProviderChain);

        return $this->helper->coreToJson($this->client->contactModify($registryId, $contactId, $payload));
    }
}
