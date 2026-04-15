<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Brizy embed step 3: Route53 hosted zone + Brizy DNS records + CORE domain.create using delegation NS.
 */
final class BrizyDomainPurchaseService
{
    public function __construct(
        private readonly Route53HostedZoneService $route53HostedZoneService,
        private readonly Route53RecordSetService $route53RecordSetService,
        private readonly DomainService $domainService,
    ) {
    }

    /**
     * @param array<string, mixed> $input domainName, registryId, contactId, dnsRecords, optional periodValue/Unit, eligibilityIntendedUse, launchPhase, amemberUserId, projectId
     *
     * @return array<string, mixed>
     */
    public function complete(array $input): array
    {
        $domainName = trim((string) ($input['domainName'] ?? ''));
        $registryId = (string) ($input['registryId'] ?? '.com');
        if ($registryId !== '' && $registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }
        $contactId = trim((string) ($input['contactId'] ?? ''));
        $dnsRecords = $input['dnsRecords'] ?? null;

        if ($domainName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domainName required', 'step' => 'validate'];
        }
        if ($contactId === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'contactId required', 'step' => 'validate'];
        }
        if (!is_array($dnsRecords) || $dnsRecords === []) {
            return ['success' => false, 'code' => 'invalid', 'message' => 'dnsRecords must be a non-empty array', 'step' => 'validate'];
        }

        $fqdn = CoreGatewayClient::fqdnForRegistry($domainName, $registryId);

        $hz = $this->route53HostedZoneService->createForDomain($fqdn, []);
        if (!($hz['success'] ?? false)) {
            return array_merge($hz, ['step' => 'hostedZone']);
        }

        $hostedZoneId = (string) ($hz['hostedZoneId'] ?? '');
        $upsert = $this->route53RecordSetService->upsertBrizyDnsRecords($hostedZoneId, $dnsRecords);
        if (!($upsert['success'] ?? false)) {
            return array_merge($upsert, [
                'step' => 'dnsRecords',
                'hostedZone' => $hz,
            ]);
        }

        $nameServers = $hz['nameServers'] ?? [];
        if (!is_array($nameServers)) {
            $nameServers = [];
        }
        $ns = $this->normalizeDelegationNameservers($nameServers);

        $createInput = [
            'domainName' => $domainName,
            'registryId' => $registryId,
            'contact' => ['id' => $contactId],
            'ns1' => $ns[0] ?? '',
            'ns2' => $ns[1] ?? '',
            'ns3' => $ns[2] ?? '',
            'ns4' => $ns[3] ?? '',
            'periodValue' => max(1, min(99, (int) ($input['periodValue'] ?? 1))),
            'periodUnit' => strtolower((string) ($input['periodUnit'] ?? 'y')) === 'm' ? 'm' : 'y',
        ];
        foreach (['eligibilityIntendedUse', 'launchPhase'] as $key) {
            if (!isset($input[$key])) {
                continue;
            }
            $v = $input[$key];
            if (!is_string($v)) {
                continue;
            }
            $v = trim($v);
            if ($v !== '') {
                $createInput[$key] = $v;
            }
        }

        $domainCreate = $this->domainService->create($createInput);
        if (!($domainCreate['success'] ?? false)) {
            return array_merge($domainCreate, [
                'step' => 'domainCreate',
                'hostedZone' => $hz,
                'dnsRecordsResult' => $upsert,
            ]);
        }

        $summary = [
            'domain' => $fqdn,
            'registryId' => $registryId,
            'contactId' => $contactId,
            'nameServers' => array_values(array_map(static function ($s) {
                return strtolower(rtrim(trim((string) $s), '.'));
            }, $nameServers)),
            'dnsRecords' => $dnsRecords,
            'hostedZoneId' => $hostedZoneId,
        ];

        return [
            'success' => true,
            'code' => '10000',
            'message' => 'Domain purchase completed.',
            'summary' => $summary,
            'hostedZone' => $hz,
            'dnsRecordsResult' => $upsert,
            'domainCreate' => $domainCreate,
            'amemberUserId' => $input['amemberUserId'] ?? null,
            'projectId' => $input['projectId'] ?? null,
        ];
    }

    /**
     * @param list<mixed> $nameServers
     *
     * @return list<string>
     */
    private function normalizeDelegationNameservers(array $nameServers): array
    {
        $out = [];
        foreach ($nameServers as $ns) {
            if (count($out) >= 4) {
                break;
            }
            $s = strtolower(rtrim(trim((string) $ns), '.'));
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }
}
