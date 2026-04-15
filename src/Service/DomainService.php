<?php

declare(strict_types=1);

namespace App\Service;

class DomainService
{
    private const INQUIRE_BATCH_MAX_DOMAINS = 50;

    public function __construct(
        private readonly CoreGatewayClient $client,
        private readonly CoreGatewayHelper $helper,
        private readonly string $providerChainSpec,
        private readonly string $providerChainType,
        private readonly string $defaultNs1,
        private readonly string $defaultNs2,
        private readonly bool $skipProviderChain,
        private readonly bool $debugRequest,
        private readonly string $domainCreateNsMandatoryRegistries,
    ) {
    }

    public function check(array $input): array
    {
        $domains = $input['domains'] ?? [];
        $registryId = (string) ($input['registryId'] ?? '.com');
        if (!is_array($domains) || $domains === []) {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domains array required'];
        }

        $checkExtra = $this->optionalLaunchPhasePayload($input);
        $response = $this->client->domainCheck($domains, $registryId, $checkExtra);
        $response = $this->helper->normalizeDomainCheckResponse($response);

        return $this->helper->coreToJson($response);
    }

    public function create(array $input): array
    {
        $domainName = strtolower(trim((string) ($input['domainName'] ?? '')));
        $registryId = (string) ($input['registryId'] ?? '.com');
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }
        if ($domainName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domainName required'];
        }

        $ns1 = trim((string) ($input['ns1'] ?? '')) ?: $this->defaultNs1;
        $ns2 = trim((string) ($input['ns2'] ?? '')) ?: $this->defaultNs2;
        $ns3 = trim((string) ($input['ns3'] ?? ''));
        $ns4 = trim((string) ($input['ns4'] ?? ''));
        if ($this->isNsMandatoryForRegistry($registryId) && $ns1 === '' && $ns2 === '' && $ns3 === '' && $ns4 === '') {
            return [
                'success' => false,
                'code' => 'invalid',
                'message' => 'Nameservers are required for this registry. Enter at least one NS (ns1–ns4) or set CORE_DEFAULT_NS1 and CORE_DEFAULT_NS2.',
            ];
        }

        $periodValue = max(1, min(99, (int) ($input['periodValue'] ?? 1)));
        $periodUnit = strtolower((string) ($input['periodUnit'] ?? 'y')) === 'm' ? 'm' : 'y';

        $payload = [
            'period.unit' => $periodUnit,
            'period.value' => (string) $periodValue,
        ];
        $contact = $input['contact'] ?? [];
        if (is_array($contact) && isset($contact['id'])) {
            $contactId = trim((string) $contact['id']);
            if (strlen($contactId) < 3 || strlen($contactId) > 16) {
                return ['success' => false, 'code' => 'invalid', 'message' => 'contact.id must be 3-16 characters'];
            }
            $techId = trim((string) ($contact['techId'] ?? ''));
            $billingId = trim((string) ($contact['billingId'] ?? ''));
            foreach (['contact.techId' => $techId, 'contact.billingId' => $billingId] as $label => $cid) {
                if ($cid === '') {
                    continue;
                }
                if (strlen($cid) < 3 || strlen($cid) > 16) {
                    return ['success' => false, 'code' => 'invalid', 'message' => $label . ' must be 3-16 characters when set'];
                }
            }
            $techId = $techId !== '' ? $techId : $contactId;
            $billingId = $billingId !== '' ? $billingId : $contactId;

            $payload['contact.1.id'] = $contactId;
            $payload['contact.1.type'] = 'registrant';
            $payload['contact.2.id'] = $contactId;
            $payload['contact.2.type'] = 'admin';
            $payload['contact.3.id'] = $techId;
            $payload['contact.3.type'] = 'tech';
            $payload['contact.4.id'] = $billingId;
            $payload['contact.4.type'] = 'billing';
        }

        foreach ([1 => $ns1, 2 => $ns2, 3 => $ns3, 4 => $ns4] as $i => $ns) {
            if ($ns !== '') {
                $payload['ns.' . $i . '.name'] = strtolower(trim($ns, " \t\n\r\0\x0B."));
            }
        }

        $payload = array_merge($payload, $this->optionalLaunchPhasePayload($input));
        $intendedUse = $this->normalizeEligibilityIntendedUse($input);
        if ($intendedUse !== null) {
            $payload['eligibility.intended-use'] = $intendedUse;
        }

        $payload = array_merge($payload, $this->dnssecCreateExtensionFromInput($input));
        $payload = $this->omitDnssecEnabledForVerisignDomainCreate($payload, $registryId);

        $payload = $this->helper->addProviderChain($payload, $this->providerChainSpec, $this->providerChainType, $this->skipProviderChain);

        $fullPayloadForDebug = array_merge([
            'request.type' => 'domain.create',
            'registry.id' => $registryId,
            'domain.name' => CoreGatewayClient::fqdnForRegistry($domainName, $registryId),
        ], $payload);

        $output = $this->helper->coreToJson($this->client->domainCreate($registryId, $domainName, $payload));
        if (($output['code'] ?? null) === '20101' && $this->debugRequest) {
            $output['debugRequestPayload'] = $fullPayloadForDebug;
        }

        return $output;
    }

    public function inquire(string $name, string $registryId = '.com'): array
    {
        if ($name === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'name required'];
        }

        return $this->helper->coreToJson($this->client->domainInquire($registryId, $name));
    }

    /**
     * domain.authinfo.request — obtain transfer / EPP authinfo for a domain on this member account.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function authinfoRequest(array $input): array
    {
        $domainName = trim((string) ($input['domainName'] ?? ''));
        $registryId = (string) ($input['registryId'] ?? '.com');
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }
        if ($domainName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domainName required'];
        }

        $extra = [];
        $domainId = trim((string) ($input['domainId'] ?? ''));
        if ($domainId !== '') {
            $extra['domain.id'] = $domainId;
        }
        $extra = $this->helper->addProviderChain($extra, $this->providerChainSpec, $this->providerChainType, $this->skipProviderChain);

        return $this->helper->coreToJson($this->client->domainAuthinfoRequest($registryId, $domainName, $extra));
    }

    /**
     * domain.inquire including authinfo (validate transfer code; returns contact handles).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function inquireForTransfer(array $input): array
    {
        $domainName = strtolower(trim((string) ($input['domainName'] ?? '')));
        $registryId = (string) ($input['registryId'] ?? '.com');
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }
        $authinfo = (string) ($input['authinfo'] ?? '');
        if ($domainName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domainName required'];
        }
        if ($authinfo === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'authinfo required'];
        }

        $response = $this->client->domainInquireWithAuth($registryId, $domainName, $authinfo);
        $wrapped = $this->helper->coreToJson($response);
        $data = $wrapped['data'] ?? [];
        if (is_array($data)) {
            $wrapped['domainContacts'] = $this->extractDomainContactSlots($data);
        }

        return $wrapped;
    }

    /**
     * domain.transfer.request — CORE Payload 2.0 name for initiating an inbound transfer.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function transferRequest(array $input): array
    {
        $domainName = trim((string) ($input['domainName'] ?? ''));
        $registryId = (string) ($input['registryId'] ?? '.com');
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }
        $authinfo = (string) ($input['authinfo'] ?? '');
        if ($domainName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domainName required'];
        }
        if ($authinfo === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'authinfo required'];
        }

        $contacts = $input['contacts'] ?? [];
        if (!is_array($contacts) || $contacts === []) {
            return ['success' => false, 'code' => 'invalid', 'message' => 'contacts array required (type + id per role)'];
        }

        $payload = ['domain.authinfo' => $authinfo];
        $i = 1;
        foreach ($contacts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            $id = trim((string) ($row['id'] ?? ''));
            if ($type === '' || $id === '') {
                continue;
            }
            if (strlen($id) < 3 || strlen($id) > 16) {
                return ['success' => false, 'code' => 'invalid', 'message' => 'Each contact id must be 3–16 characters'];
            }
            $payload['contact.' . $i . '.type'] = $type;
            $payload['contact.' . $i . '.id'] = $id;
            ++$i;
        }
        if ($i === 1) {
            return ['success' => false, 'code' => 'invalid', 'message' => 'At least one contact with type and id required'];
        }

        $update = trim((string) ($input['update'] ?? ''));
        if ($update !== '') {
            $payload['update'] = $update;
        }

        foreach ([1 => 'ns1', 2 => 'ns2', 3 => 'ns3', 4 => 'ns4'] as $n => $key) {
            $ns = trim((string) ($input[$key] ?? ''));
            if ($ns !== '') {
                $payload['ns.' . $n . '.name'] = strtolower(rtrim($ns, '.'));
            }
        }

        $periodValue = (int) ($input['periodValue'] ?? 0);
        $periodUnit = strtolower((string) ($input['periodUnit'] ?? ''));
        if ($periodValue > 0 && ($periodUnit === 'y' || $periodUnit === 'm')) {
            $payload['period.value'] = (string) $periodValue;
            $payload['period.unit'] = $periodUnit;
        }

        $payload = $this->helper->addProviderChain($payload, $this->providerChainSpec, $this->providerChainType, $this->skipProviderChain);

        return $this->helper->coreToJson($this->client->domainTransferRequest($registryId, $domainName, $payload));
    }

    /**
     * domain.transfer.reply — approve or reject as losing registrar (often after a poll message).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function transferReply(array $input): array
    {
        $domainName = trim((string) ($input['domainName'] ?? ''));
        $registryId = trim((string) ($input['registryId'] ?? ''));
        if ($registryId === '') {
            $registryId = '.com';
        }
        // Poll payloads often use backend registry tokens (e.g. afilias) without a leading dot.
        if ($registryId[0] !== '.' && preg_match('/^[a-z0-9]{2,3}$/i', $registryId)) {
            $registryId = '.' . strtolower($registryId);
        }
        if ($domainName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domainName required'];
        }

        $reply = strtolower(trim((string) ($input['transferReply'] ?? '')));
        if ($reply !== 'approve' && $reply !== 'reject') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'transferReply must be approve or reject'];
        }

        $payload = ['transfer.reply' => $reply];
        if ($reply === 'reject') {
            $reason = strtolower(trim((string) ($input['transferReplyReason'] ?? '')));
            if ($reason === '') {
                return ['success' => false, 'code' => 'invalid', 'message' => 'transferReplyReason required when rejecting'];
            }
            $payload['transfer.reply.reason'] = $reason;
        }

        $comment = trim((string) ($input['transferReplyComment'] ?? ''));
        if ($comment !== '') {
            $payload['transfer.reply.comment'] = $comment;
        }

        $domainId = trim((string) ($input['domainId'] ?? ''));
        if ($domainId !== '') {
            $payload['domain.id'] = $domainId;
        }

        $payload = $this->helper->addProviderChain($payload, $this->providerChainSpec, $this->providerChainType, $this->skipProviderChain);

        return $this->helper->coreToJson($this->client->domainTransferReply($registryId, $domainName, $payload));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{index: int, type: string, id: string}>
     */
    private function extractDomainContactSlots(array $data): array
    {
        $slots = [];
        for ($n = 1; $n <= 20; ++$n) {
            $idKey = 'contact.' . $n . '.id';
            if (!isset($data[$idKey])) {
                continue;
            }
            $rawId = $data[$idKey];
            $id = is_array($rawId) ? trim((string) ($rawId[0] ?? '')) : trim((string) $rawId);
            if ($id === '') {
                continue;
            }
            $typeKey = 'contact.' . $n . '.type';
            $rawType = $data[$typeKey] ?? '';
            $type = is_array($rawType) ? strtolower(trim((string) ($rawType[0] ?? ''))) : strtolower(trim((string) $rawType));

            $slots[] = ['index' => $n, 'type' => $type !== '' ? $type : 'unknown', 'id' => $id];
        }

        return $slots;
    }

    public function inquireByContact(array $input): array
    {
        $domainName = strtolower(trim((string) ($input['domainName'] ?? '')));
        $contactId = trim((string) ($input['contactId'] ?? ''));
        $registryId = (string) ($input['registryId'] ?? '.com');

        if ($domainName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domainName required'];
        }
        if ($contactId === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'contactId required'];
        }
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }

        $result = $this->helper->coreToJson($this->client->domainInquire($registryId, $domainName));
        $data = $result['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        $matchedRoles = $this->matchedContactRoles($data, $contactId);

        $result['contactLinked'] = $matchedRoles !== [];
        $result['matchedRoles'] = $matchedRoles;

        if (($result['success'] ?? false) && !$result['contactLinked']) {
            $result['message'] = 'Domain exists but provided contactId is not linked to returned contact roles.';
        }

        return $result;
    }

    /**
     * Runs domain.inquire for each name and reports whether contactId matches any contact.N.id in the response.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function inquireBatchForContact(array $input): array
    {
        $contactId = trim((string) ($input['contactId'] ?? ''));
        $registryId = (string) ($input['registryId'] ?? '.com');
        if ($contactId === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'contactId required'];
        }
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }

        $rawNames = $input['domainNames'] ?? [];
        if (!is_array($rawNames)) {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domainNames array required'];
        }

        $unique = [];
        foreach ($rawNames as $n) {
            $name = strtolower(trim((string) $n));
            $name = rtrim($name, '.');
            if ($name !== '') {
                $unique[$name] = true;
            }
        }
        $names = array_keys($unique);
        if ($names === []) {
            return ['success' => false, 'code' => 'invalid', 'message' => 'At least one domain name required'];
        }
        if (count($names) > self::INQUIRE_BATCH_MAX_DOMAINS) {
            return [
                'success' => false,
                'code' => 'invalid',
                'message' => 'At most ' . (string) self::INQUIRE_BATCH_MAX_DOMAINS . ' domain names allowed',
            ];
        }

        $results = [];
        foreach ($names as $domainName) {
            $coreResponse = $this->client->domainInquire($registryId, $domainName);
            $wrapped = $this->helper->coreToJson($coreResponse);
            $data = $wrapped['data'] ?? [];
            if (!is_array($data)) {
                $data = [];
            }

            $matchedRoles = $this->matchedContactRoles($data, $contactId);
            $results[] = [
                'domainName' => $domainName,
                'success' => (bool) ($wrapped['success'] ?? false),
                'code' => $wrapped['code'] ?? null,
                'message' => $wrapped['message'] ?? null,
                'contactLinked' => $matchedRoles !== [],
                'matchedRoles' => $matchedRoles,
                'expirationDate' => $this->firstDataString($data, ['expiration.date', 'domain.expiration.date', 'expire.date']),
                'domainId' => $this->firstDataString($data, ['domain.id', 'DOMAIN.ID']),
                'domainNameI15d' => $this->firstDataString($data, ['domain.name.i15d', 'DOMAIN.NAME.I15D']),
                'domainStatuses' => $this->extractDomainStatusesFromData($data),
            ];
        }

        return [
            'success' => true,
            'registryId' => $registryId,
            'contactId' => $contactId,
            'results' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function matchedContactRoles(array $data, string $contactId): array
    {
        $matchedRoles = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $keyLower = strtolower($key);
            if (!preg_match('/^contact\.(\d+)\.id$/', $keyLower, $matches)) {
                continue;
            }

            $candidate = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            if ($candidate !== $contactId) {
                continue;
            }

            $contactIndex = $matches[1];
            $roleKey = 'contact.' . $contactIndex . '.type';
            $roleValue = $data[$roleKey] ?? $data[strtoupper($roleKey)] ?? null;
            $role = is_array($roleValue) ? (string) ($roleValue[0] ?? '') : (string) ($roleValue ?? '');
            $matchedRoles[] = $role !== '' ? $role : 'unknown';
        }

        return array_values(array_unique($matchedRoles));
    }

    /**
     * EPP / CORE domain status values from domain.inquire (e.g. domain.status, comma-separated, or domain.status.N).
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function extractDomainStatusesFromData(array $data): array
    {
        $out = [];
        foreach (['domain.status', 'DOMAIN.STATUS'] as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            $value = $data[$key];
            $s = is_array($value) ? trim((string) ($value[0] ?? '')) : trim((string) $value);
            if ($s === '') {
                continue;
            }
            foreach (array_map(trim(...), explode(',', $s)) as $part) {
                if ($part !== '') {
                    $out[] = $part;
                }
            }

            break;
        }

        for ($n = 1; $n <= 30; ++$n) {
            foreach (['domain.status.' . $n, 'DOMAIN.STATUS.' . $n] as $key) {
                if (!isset($data[$key])) {
                    continue;
                }
                $value = $data[$key];
                $s = is_array($value) ? trim((string) ($value[0] ?? '')) : trim((string) $value);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     */
    private function firstDataString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            $value = $data[$key];
            $s = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            $s = trim($s);
            if ($s !== '') {
                return $s;
            }
        }

        return null;
    }

    public function renew(array $input): array
    {
        $domainName = trim((string) ($input['domainName'] ?? ''));
        if ($domainName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domainName required'];
        }
        $registryId = (string) ($input['registryId'] ?? '.com');
        $periodValue = (int) ($input['periodValue'] ?? 1);
        $periodUnit = (string) ($input['periodUnit'] ?? 'y');

        return $this->helper->coreToJson($this->client->domainRenew($registryId, $domainName, $periodUnit, $periodValue));
    }

    public function delete(array $input): array
    {
        $domainName = trim((string) ($input['domainName'] ?? ''));
        if ($domainName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domainName required'];
        }

        $registryId = (string) ($input['registryId'] ?? '.com');
        return $this->helper->coreToJson($this->client->domainDelete($registryId, $domainName));
    }

    public function statusModify(array $input): array
    {
        $domainName = trim((string) ($input['domainName'] ?? ''));
        $domainId = trim((string) ($input['domainId'] ?? ''));
        if ($domainName === '' && $domainId === '') {
            return [
                'success' => false,
                'code' => 'invalid',
                'message' => 'Provide domainName and/or domainId (Payload 2.0: at least one required; the spec example sends both domain.name and domain.id together).',
            ];
        }
        $registryId = (string) ($input['registryId'] ?? '.com');
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }

        $statusAdd = $this->normalizeCoreStatusList((string) ($input['statusAdd'] ?? $input['domain.status.add'] ?? ''));
        $statusRemove = $this->normalizeCoreStatusList((string) ($input['statusRemove'] ?? $input['domain.status.remove'] ?? ''));
        if ($statusAdd === '' && $statusRemove === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domain.status.add or domain.status.remove required'];
        }

        $extra = [];
        if ($statusAdd !== '') {
            $extra['domain.status.add'] = $statusAdd;
        }
        if ($statusRemove !== '') {
            $extra['domain.status.remove'] = $statusRemove;
        }

        // Spec: at least one of domain.name / domain.id. Sending a stale or backend-mismatched domain.id
        // with domain.name can yield 23003 "Object does not exist" on domain.id — default is name-only.
        $includeDomainId = $input['includeDomainId'] ?? null;
        if ($includeDomainId === null) {
            $includeDomainId = $domainName === '';
        }
        if ((bool) $includeDomainId && $domainId !== '') {
            $extra['domain.id'] = $domainId;
        }

        $i15d = trim((string) ($input['domainNameI15d'] ?? $input['domain.name.i15d'] ?? ''));
        if ($i15d !== '') {
            $extra['domain.name.i15d'] = $i15d;
        }

        $otp = trim((string) ($input['transactionOtp'] ?? $input['transaction.otp'] ?? ''));
        if ($otp !== '') {
            $extra['transaction.otp'] = $otp;
        }
        $atp = trim((string) ($input['transactionAtp'] ?? $input['transaction.atp'] ?? ''));
        if ($atp !== '') {
            $extra['transaction.atp'] = $atp;
        }

        $effectiveMemberId = trim((string) ($input['effectiveMemberId'] ?? $input['effective.member.id'] ?? ''));
        if ($effectiveMemberId !== '') {
            $extra['effective.member.id'] = $effectiveMemberId;
        }

        try {
            return $this->helper->coreToJson($this->client->domainStatusModify($registryId, $domainName, $extra));
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'code' => 'invalid', 'message' => $e->getMessage()];
        }
    }

    /**
     * Payload 2.0 allows comma- or space-separated status tokens; normalize to one comma-separated list.
     */
    private function normalizeCoreStatusList(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(',', $parts);
    }

    public function modify(array $input): array
    {
        $domainName = trim((string) ($input['domainName'] ?? ''));
        if ($domainName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domainName required'];
        }
        $registryId = (string) ($input['registryId'] ?? '.com');
        $extra = ['update' => 'nameservers'];
        $ns1 = trim((string) ($input['ns1'] ?? ''));
        $ns2 = trim((string) ($input['ns2'] ?? ''));
        $ns3 = trim((string) ($input['ns3'] ?? ''));
        $ns4 = trim((string) ($input['ns4'] ?? ''));
        foreach ([1 => $ns1, 2 => $ns2, 3 => $ns3, 4 => $ns4] as $i => $ns) {
            if ($ns !== '') {
                $extra['ns.' . $i . '.name'] = $ns;
            }
        }

        return $this->helper->coreToJson($this->client->domainModify($registryId, $domainName, $extra));
    }

    private function isNsMandatoryForRegistry(string $registryId): bool
    {
        $raw = trim($this->domainCreateNsMandatoryRegistries);
        if ($raw === '') {
            return false;
        }

        $normalized = $registryId !== '' && $registryId[0] !== '.' ? '.' . $registryId : $registryId;
        $normalized = strtolower($normalized);

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $candidate = $entry[0] !== '.' ? '.' . $entry : $entry;
            if (strtolower($candidate) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private function optionalLaunchPhasePayload(array $input): array
    {
        $phase = trim((string) ($input['launchPhase'] ?? ''));
        if ($phase === '') {
            return [];
        }

        return ['launch.phase' => $phase];
    }

    /**
     * Optional DNSSEC extension for domain.create (Payload 2.0: dnssec.enabled, dnssec.ds.1.*).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private function dnssecCreateExtensionFromInput(array $input): array
    {
        $ext = $input['dnssecCoreExtension'] ?? null;
        if (is_array($ext) && $ext !== []) {
            $out = [];
            foreach ($ext as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                $s = trim((string) $v);
                if ($s !== '') {
                    $out[$k] = $s;
                }
            }

            return $out;
        }

        $on = $input['dnssecEnabled'] ?? false;
        if ($on !== true && $on !== 'true' && $on !== '1' && $on !== 1) {
            return [];
        }

        $ds = $input['dnssecDs'] ?? null;
        if (!is_array($ds)) {
            return ['dnssec.enabled' => 'true'];
        }

        $keytag = trim((string) ($ds['keyTag'] ?? $ds['keytag'] ?? ''));
        $alg = trim((string) ($ds['algorithm'] ?? $ds['alg'] ?? ''));
        $digestType = trim((string) ($ds['digestType'] ?? $ds['digesttype'] ?? ''));
        $digestHex = trim((string) ($ds['digestHex'] ?? $ds['digest'] ?? ''));

        $out = ['dnssec.enabled' => 'true'];
        if ($keytag !== '') {
            $out['dnssec.ds.1.ds.keytag'] = $keytag;
        }
        if ($alg !== '') {
            $out['dnssec.ds.1.ds.alg'] = $alg;
        }
        if ($digestType !== '') {
            $out['dnssec.ds.1.ds.digesttype'] = $digestType;
        }
        if ($digestHex !== '') {
            $out['dnssec.ds.1.ds.digest.hex'] = strtolower(preg_replace('/\s+/', '', $digestHex) ?? $digestHex);
        }

        $flags = trim((string) ($ds['keyFlags'] ?? ''));
        if ($flags !== '') {
            $out['dnssec.ds.1.key.flags'] = $flags;
        }
        $proto = trim((string) ($ds['keyProtocol'] ?? ''));
        if ($proto !== '') {
            $out['dnssec.ds.1.key.protocol'] = $proto;
        }
        $keyAlg = trim((string) ($ds['keyAlg'] ?? ''));
        if ($keyAlg !== '') {
            $out['dnssec.ds.1.key.alg'] = $keyAlg;
        }
        $pubB64 = trim((string) ($ds['pubkeyBase64'] ?? ''));
        if ($pubB64 !== '') {
            $out['dnssec.ds.1.key.pubkey.base64'] = preg_replace('/\s+/', '', $pubB64) ?? $pubB64;
        }

        return $out;
    }

    /**
     * Verisign (.com / .net) returns CORE 20102 "Field invalid" on dnssec.enabled when DS/DNSKEY fields are present.
     * Omit the flag for those registries; DS + key material are still sent.
     *
     * @param array<string, string> $payload
     *
     * @return array<string, string>
     */
    private function omitDnssecEnabledForVerisignDomainCreate(array $payload, string $registryId): array
    {
        $rid = strtolower($registryId);
        if ($rid !== '.com' && $rid !== '.net') {
            return $payload;
        }

        $hasOtherDnssec = false;
        foreach (array_keys($payload) as $k) {
            $key = (string) $k;
            if ($key === 'dnssec.enabled') {
                continue;
            }
            if (str_starts_with($key, 'dnssec.')) {
                $hasOtherDnssec = true;
                break;
            }
        }

        if ($hasOtherDnssec) {
            unset($payload['dnssec.enabled']);
        }

        return $payload;
    }

    /**
     * Normalizes eligibility.intended-use (Payload 2.0); required for some registries (e.g. doteus) per launch phase.
     *
     * @param array<string, mixed> $input
     */
    private function normalizeEligibilityIntendedUse(array $input): ?string
    {
        $raw = $input['eligibilityIntendedUse'] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $text = trim($raw);
        if ($text === '') {
            return null;
        }
        if (strlen($text) > 4000) {
            return substr($text, 0, 4000);
        }

        return $text;
    }
}
