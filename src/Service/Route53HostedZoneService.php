<?php

declare(strict_types=1);

namespace App\Service;

use Aws\Exception\AwsException;
use Aws\Route53\Route53Client;

final class Route53HostedZoneService
{
    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $region,
        private readonly string $defaultDnssecKmsKeyArn = '',
    ) {
    }

    /**
     * Create a public Route53 hosted zone and return delegation name servers, or resolve an existing zone.
     * Optional DNSSEC: create KSK, activate, enable signing, return DS data for CORE domain.create.
     *
     * @param array{enableDnssec?: bool, dnssecKmsKeyArn?: string} $options
     *
     * @return array<string, mixed>
     */
    public function createForDomain(string $fqdn, array $options = []): array
    {
        $dnsName = $this->normalizeDnsName($fqdn);
        if ($dnsName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'domainName required'];
        }

        if (trim($this->accessKeyId) === '' || trim($this->secretAccessKey) === '') {
            return [
                'success' => false,
                'code' => 'config',
                'message' => 'AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY must be set to create a hosted zone.',
            ];
        }

        $client = $this->createClient();
        $enableDnssec = (bool) ($options['enableDnssec'] ?? false);
        $kmsOverride = trim((string) ($options['dnssecKmsKeyArn'] ?? ''));
        $kmsArn = $kmsOverride !== '' ? $kmsOverride : trim($this->defaultDnssecKmsKeyArn);

        try {
            $result = $client->createHostedZone([
                'Name' => $dnsName,
                'CallerReference' => 'puntu-hz-' . uniqid('', true),
                'HostedZoneConfig' => [
                    'Comment' => 'Created by puntu-symfony-app',
                    'PrivateZone' => false,
                ],
            ]);

            $payload = $this->successPayload($result->toArray(), $dnsName, created: true);
        } catch (AwsException $e) {
            if ($this->isHostedZoneAlreadyExists($e)) {
                $resolved = $this->fetchDelegationForExistingZone($client, $dnsName);
                if (($resolved['success'] ?? false) === true) {
                    $resolved['message'] = ($resolved['message'] ?? '') !== ''
                        ? $resolved['message']
                        : 'Hosted zone already existed; returned delegation name servers.';

                    $payload = $resolved;
                } else {
                    return [
                        'success' => false,
                        'code' => $e->getAwsErrorCode() ?: 'aws',
                        'message' => $this->awsExceptionMessage($e),
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'code' => $e->getAwsErrorCode() ?: 'aws',
                    'message' => $this->awsExceptionMessage($e),
                ];
            }
        }

        if ($enableDnssec) {
            if ($kmsArn === '') {
                $payload['dnssec'] = [
                    'success' => false,
                    'code' => 'config',
                    'message' => 'DNSSEC requested but no KMS key ARN. Set AWS_ROUTE53_DNSSEC_KMS_KEY_ARN or pass dnssecKmsKeyArn (ARN of an existing Route 53–compatible asymmetric signing key in KMS, e.g. ECC_NIST_P256; reuse one key for multiple zones).',
                ];
            } else {
                $hostedZoneId = (string) ($payload['hostedZoneId'] ?? '');
                $payload['dnssec'] = $this->enableDnssecSigning($client, $hostedZoneId, $kmsArn);
                if (($payload['dnssec']['success'] ?? false) === true) {
                    $payload['coreDnssecCreateExtension'] = $payload['dnssec']['coreDnssecCreateExtension'] ?? [];
                    $payload['dnssecDsRecords'] = $payload['dnssec']['dsRecords'] ?? [];
                }
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function dnssecFailureFromAwsException(AwsException $e, string $messagePrefix = ''): array
    {
        $code = $e->getAwsErrorCode() ?: 'aws';
        $message = $messagePrefix . $this->awsExceptionMessage($e);

        $out = [
            'success' => false,
            'code' => $code,
            'message' => $message,
        ];

        if ($code === 'InvalidKMSArn') {
            $out['hint'] = 'The ARN is often valid but the KMS key policy is wrong for Route 53 DNSSEC. Add statements allowing principal "dnssec-route53.amazonaws.com" for kms:DescribeKey, kms:GetPublicKey, kms:Sign, and kms:CreateGrant (with Condition Bool kms:GrantIsForAWSResource = true). The key must be customer-managed asymmetric ECC_NIST_P256 in us-east-1. Your IAM principal also needs permission to use that key. After fixing the policy, delete any failed KSK in the console or retry with a new zone.';
            $out['documentationUrl'] = 'https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/access-control-managing-permissions.html#KMS-key-policy-for-DNSSEC';
        }

        return $out;
    }

    private function createClient(): Route53Client
    {
        return new Route53Client([
            'version' => '2013-04-01',
            'region' => $this->region !== '' ? $this->region : 'us-east-1',
            'credentials' => [
                'key' => $this->accessKeyId,
                'secret' => $this->secretAccessKey,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function enableDnssecSigning(Route53Client $client, string $hostedZoneId, string $kmsKeyArn): array
    {
        if ($hostedZoneId === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'hostedZoneId missing for DNSSEC.'];
        }

        $kskName = 'puntu-' . preg_replace('/[^a-zA-Z0-9_-]/', '', uniqid('ksk', true));
        if (strlen($kskName) < 3) {
            $kskName = 'puntu-ksk-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }

        try {
            $client->createKeySigningKey([
                'HostedZoneId' => $hostedZoneId,
                'CallerReference' => 'puntu-ksk-' . uniqid('', true),
                'KeyManagementServiceArn' => $kmsKeyArn,
                'Name' => $kskName,
                'Status' => 'INACTIVE',
            ]);
        } catch (AwsException $e) {
            return $this->dnssecFailureFromAwsException($e);
        }

        try {
            $client->activateKeySigningKey([
                'HostedZoneId' => $hostedZoneId,
                'Name' => $kskName,
            ]);
        } catch (AwsException $e) {
            return $this->dnssecFailureFromAwsException($e, 'activateKeySigningKey failed: ');
        }

        try {
            $client->enableHostedZoneDNSSEC([
                'HostedZoneId' => $hostedZoneId,
            ]);
        } catch (AwsException $e) {
            return $this->dnssecFailureFromAwsException($e, 'enableHostedZoneDNSSEC failed: ');
        }

        $dsRecords = [];
        for ($attempt = 0; $attempt < 15; ++$attempt) {
            if ($attempt > 0) {
                usleep(400_000);
            }
            try {
                $raw = $client->getDNSSEC(['HostedZoneId' => $hostedZoneId])->toArray();
            } catch (AwsException $e) {
                return $this->dnssecFailureFromAwsException($e, 'getDNSSEC failed: ');
            }
            $dsRecords = $this->extractDsRecordsFromGetDnssec($raw, $kskName);
            if ($dsRecords !== []) {
                break;
            }
        }

        if ($dsRecords === []) {
            return [
                'success' => false,
                'code' => 'dnssec_ds_pending',
                'message' => 'DNSSEC enabled but DS records were not returned yet. Retry getDNSSEC in AWS or run domain.create with dnssec.enabled only, then add DS via domain.modify.',
                'keySigningKeyName' => $kskName,
            ];
        }

        $first = $dsRecords[0];
        $core = $this->buildCoreDnssecExtension($first);
        $publicDs = array_map(static function (array $r): array {
            return [
                'keyTag' => $r['keyTag'],
                'algorithm' => $r['algorithm'],
                'digestType' => $r['digestType'],
                'digest' => $r['digest'],
            ];
        }, $dsRecords);

        return [
            'success' => true,
            'code' => '10000',
            'message' => 'Route53 DNSSEC signing enabled.',
            'keySigningKeyName' => $kskName,
            'dsRecords' => $publicDs,
            'coreDnssecCreateExtension' => $core,
        ];
    }

    /**
     * @param array<string, mixed> $getDnssecResponse
     *
     * @return list<array{keyTag: int, algorithm: int, digestType: int, digest: string, ksk: array<string, mixed>}>
     */
    private function extractDsRecordsFromGetDnssec(array $getDnssecResponse, string $preferredKskName): array
    {
        $ksks = $getDnssecResponse['KeySigningKeys'] ?? [];
        if (!is_array($ksks)) {
            return [];
        }

        $out = [];
        foreach ($ksks as $ksk) {
            if (!is_array($ksk) || ($ksk['Name'] ?? '') !== $preferredKskName) {
                continue;
            }
            $keyTag = (int) ($ksk['KeyTag'] ?? 0);
            $digest = isset($ksk['DigestValue']) ? strtolower(preg_replace('/\s+/', '', (string) $ksk['DigestValue'])) : '';
            if ($keyTag === 0 || $digest === '') {
                continue;
            }
            $out[] = [
                'keyTag' => $keyTag,
                'algorithm' => (int) ($ksk['SigningAlgorithmType'] ?? 0),
                'digestType' => (int) ($ksk['DigestAlgorithmType'] ?? 0),
                'digest' => $digest,
                'ksk' => $ksk,
            ];
        }

        return $out;
    }

    /**
     * @param array{keyTag: int, algorithm: int, digestType: int, digest: string, ksk: array<string, mixed>} $row
     *
     * @return array<string, string>
     */
    private function buildCoreDnssecExtension(array $row): array
    {
        $ds = $row;
        $ksk = $row['ksk'];
        $alg = $ds['algorithm'] !== 0 ? $ds['algorithm'] : (int) ($ksk['SigningAlgorithmType'] ?? 13);

        $ext = [
            'dnssec.enabled' => 'true',
            'dnssec.ds.1.ds.keytag' => (string) $ds['keyTag'],
            'dnssec.ds.1.ds.alg' => (string) $alg,
            'dnssec.ds.1.ds.digesttype' => (string) $ds['digestType'],
            'dnssec.ds.1.ds.digest.hex' => $ds['digest'],
        ];

        $flags = (int) ($ksk['Flag'] ?? 257);
        $ext['dnssec.ds.1.key.flags'] = (string) $flags;
        $ext['dnssec.ds.1.key.protocol'] = '3';
        $ext['dnssec.ds.1.key.alg'] = (string) $alg;

        $pubkeyB64 = $this->dnskeyPubkeyBase64ForCore($ksk, $flags, 3, $alg);
        if ($pubkeyB64 !== '') {
            $ext['dnssec.ds.1.key.pubkey.base64'] = $pubkeyB64;
        }

        return $ext;
    }

    /**
     * CORE expects dnssec.ds.1.key.pubkey.base64 to be RFC 4648 Base64 of the DNSKEY public key only.
     * Route53's DNSKEYRecord is presentation form "flags protocol algorithm <base64...>"; stripping all
     * whitespace incorrectly prepends digits (e.g. 257+3+13) and breaks the gateway validator.
     *
     * @param array<string, mixed> $ksk
     */
    private function dnskeyPubkeyBase64ForCore(array $ksk, int $flags, int $protocol, int $algorithm): string
    {
        $prefix = (string) $flags . (string) $protocol . (string) $algorithm;

        $record = isset($ksk['DNSKEYRecord']) ? trim((string) $ksk['DNSKEYRecord']) : '';
        if ($record !== '') {
            $normalized = preg_replace('/\s+/u', ' ', $record) ?? $record;
            if (preg_match('/^\d+\s+\d+\s+\d+\s+(.+)$/s', $normalized, $m)) {
                return preg_replace('/\s+/', '', $m[1]) ?? '';
            }
            $compactRecord = preg_replace('/\s+/', '', $record) ?? '';
            if ($compactRecord !== '' && str_starts_with($compactRecord, $prefix)) {
                return substr($compactRecord, strlen($prefix));
            }
        }

        $publicKey = isset($ksk['PublicKey']) ? trim((string) $ksk['PublicKey']) : '';
        if ($publicKey === '') {
            return '';
        }

        $compact = preg_replace('/\s+/', '', $publicKey) ?? '';
        if ($compact === '') {
            return '';
        }

        if (str_starts_with($compact, $prefix)) {
            return substr($compact, strlen($prefix));
        }

        if (preg_match('/^[A-Za-z0-9+\/]+=*$/D', $compact)) {
            return $compact;
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(array $result, string $dnsName, bool $created): array
    {
        $hz = $result['HostedZone'] ?? [];
        $hostedZoneId = isset($hz['Id']) ? (string) $hz['Id'] : '';
        $delegation = $result['DelegationSet']['NameServers'] ?? [];
        $nameServers = $this->normalizeNameServers(is_array($delegation) ? $delegation : []);
        $changeId = isset($result['ChangeInfo']['Id']) ? (string) $result['ChangeInfo']['Id'] : null;

        return [
            'success' => true,
            'code' => '10000',
            'message' => $created ? 'Hosted zone created successfully.' : 'Hosted zone resolved.',
            'dnsName' => rtrim($dnsName, '.'),
            'hostedZoneId' => $hostedZoneId,
            'nameServers' => $nameServers,
            'changeId' => $changeId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDelegationForExistingZone(Route53Client $client, string $dnsName): array
    {
        $list = $client->listHostedZonesByName([
            'DNSName' => $dnsName,
            'MaxItems' => '100',
        ]);

        $zones = $list['HostedZones'] ?? [];
        if (!is_array($zones)) {
            return ['success' => false, 'code' => 'not_found', 'message' => 'No matching hosted zone found.'];
        }

        $zoneId = null;
        foreach ($zones as $z) {
            if (!is_array($z)) {
                continue;
            }
            $name = isset($z['Name']) ? (string) $z['Name'] : '';
            if (strcasecmp(rtrim($name, '.'), rtrim($dnsName, '.')) === 0) {
                $zoneId = isset($z['Id']) ? (string) $z['Id'] : null;
                break;
            }
        }

        if ($zoneId === null || $zoneId === '') {
            return ['success' => false, 'code' => 'not_found', 'message' => 'No hosted zone with this DNS name was found.'];
        }

        $get = $client->getHostedZone(['Id' => $zoneId]);
        $delegation = $get['DelegationSet']['NameServers'] ?? [];
        $nameServers = $this->normalizeNameServers(is_array($delegation) ? $delegation : []);

        return [
            'success' => true,
            'code' => '10000',
            'message' => 'Hosted zone already exists; delegation name servers retrieved.',
            'dnsName' => rtrim($dnsName, '.'),
            'hostedZoneId' => $zoneId,
            'nameServers' => $nameServers,
        ];
    }

    /**
     * @param list<mixed> $raw
     *
     * @return list<string>
     */
    private function normalizeNameServers(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            $s = strtolower(rtrim(trim((string) $item), '.'));
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeDnsName(string $fqdn): string
    {
        $s = strtolower(trim($fqdn));
        $s = rtrim($s, '.');
        if ($s === '') {
            return '';
        }

        return $s . '.';
    }

    private function isHostedZoneAlreadyExists(AwsException $e): bool
    {
        $code = $e->getAwsErrorCode();
        if ($code !== null && strcasecmp($code, 'HostedZoneAlreadyExists') === 0) {
            return true;
        }

        $msg = $this->awsExceptionMessage($e);

        return stripos($msg, 'already hosted') !== false || stripos($msg, 'HostedZoneAlreadyExists') !== false;
    }

    private function awsExceptionMessage(AwsException $e): string
    {
        if (method_exists($e, 'getAwsErrorMessage')) {
            $m = (string) $e->getAwsErrorMessage();
            if ($m !== '') {
                return $m;
            }
        }

        return $e->getMessage();
    }
}
