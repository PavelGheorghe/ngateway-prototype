<?php

declare(strict_types=1);

namespace App\Service;

use Aws\Exception\AwsException;
use Aws\Route53\Route53Client;

/**
 * Applies Brizy-style DNS rows (type/host/value/ttl) to an existing Route53 hosted zone.
 */
final class Route53RecordSetService
{
    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $region,
    ) {
    }

    /**
     * @param list<array{type?: string, host?: string, value?: string, ttl?: int}> $records
     *
     * @return array<string, mixed>
     */
    public function upsertBrizyDnsRecords(string $hostedZoneId, array $records): array
    {
        $hostedZoneId = trim($hostedZoneId);
        if ($hostedZoneId === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'hostedZoneId required'];
        }

        if ($records === []) {
            return ['success' => false, 'code' => 'invalid', 'message' => 'dnsRecords must not be empty'];
        }

        if (trim($this->accessKeyId) === '' || trim($this->secretAccessKey) === '') {
            return [
                'success' => false,
                'code' => 'config',
                'message' => 'AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY must be set.',
            ];
        }

        $changes = [];
        foreach ($records as $row) {
            if (!is_array($row)) {
                continue;
            }
            $set = $this->mapBrizyRowToResourceRecordSet($row);
            if ($set === null) {
                return ['success' => false, 'code' => 'invalid', 'message' => 'Invalid DNS record row'];
            }
            $changes[] = [
                'Action' => 'UPSERT',
                'ResourceRecordSet' => $set,
            ];
        }

        if ($changes === []) {
            return ['success' => false, 'code' => 'invalid', 'message' => 'No valid DNS records'];
        }

        $client = $this->createClient();

        try {
            $result = $client->changeResourceRecordSets([
                'HostedZoneId' => $hostedZoneId,
                'ChangeBatch' => [
                    'Changes' => $changes,
                ],
            ]);
            $changeInfo = $result->get('ChangeInfo');

            return [
                'success' => true,
                'code' => '10000',
                'message' => 'DNS records updated.',
                'changeId' => is_array($changeInfo) ? ($changeInfo['Id'] ?? null) : null,
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'code' => $e->getAwsErrorCode() ?: 'aws',
                'message' => $e->getAwsErrorMessage() ?: $e->getMessage(),
            ];
        }
    }

    /**
     * List A/AAAA/CNAME record sets (non-alias) as Brizy-shaped rows for display and re-submission via upsertBrizyDnsRecords.
     *
     * @return array<string, mixed>
     */
    public function listBrizyShapedRecords(string $hostedZoneId): array
    {
        $hostedZoneId = trim($hostedZoneId);
        if ($hostedZoneId === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'hostedZoneId required'];
        }

        if (trim($this->accessKeyId) === '' || trim($this->secretAccessKey) === '') {
            return [
                'success' => false,
                'code' => 'config',
                'message' => 'AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY must be set.',
            ];
        }

        $client = $this->createClient();
        $rows = [];
        $params = [
            'HostedZoneId' => $hostedZoneId,
            'MaxItems' => '100',
        ];

        try {
            while (true) {
                $result = $client->listResourceRecordSets($params)->toArray();
                foreach ($result['ResourceRecordSets'] ?? [] as $set) {
                    if (!is_array($set)) {
                        continue;
                    }
                    $type = strtoupper((string) ($set['Type'] ?? ''));
                    if (!in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
                        continue;
                    }
                    if (!empty($set['AliasTarget'])) {
                        continue;
                    }
                    $name = (string) ($set['Name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $host = str_ends_with($name, '.') ? $name : $name . '.';
                    $ttl = (int) ($set['TTL'] ?? 300);
                    if ($ttl < 60) {
                        $ttl = 60;
                    }
                    foreach ((array) ($set['ResourceRecords'] ?? []) as $rr) {
                        if (!is_array($rr)) {
                            continue;
                        }
                        $value = trim((string) ($rr['Value'] ?? ''));
                        if ($value === '') {
                            continue;
                        }
                        if ($type === 'CNAME') {
                            $value = rtrim($value, '.');
                        }
                        $rows[] = [
                            'type' => $type,
                            'host' => $host,
                            'value' => $value,
                            'ttl' => $ttl,
                        ];
                    }
                }

                if (empty($result['IsTruncated'])) {
                    break;
                }
                $params['StartRecordName'] = $result['NextRecordName'] ?? '';
                $params['StartRecordType'] = $result['NextRecordType'] ?? '';
            }
        } catch (AwsException $e) {
            return [
                'success' => false,
                'code' => $e->getAwsErrorCode() ?: 'aws',
                'message' => $e->getAwsErrorMessage() ?: $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'code' => '10000',
            'records' => $rows,
            'hostedZoneId' => $hostedZoneId,
        ];
    }

    /**
     * @param array{type?: string, host?: string, value?: string, ttl?: int} $row
     *
     * @return array<string, mixed>|null
     */
    private function mapBrizyRowToResourceRecordSet(array $row): ?array
    {
        $type = strtoupper(trim((string) ($row['type'] ?? '')));
        $host = trim((string) ($row['host'] ?? ''));
        $value = trim((string) ($row['value'] ?? ''));
        $ttl = (int) ($row['ttl'] ?? 300);
        if ($ttl < 60) {
            $ttl = 60;
        }
        if ($type === '' || $host === '' || $value === '') {
            return null;
        }
        if (!in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            return null;
        }

        $name = str_ends_with($host, '.') ? $host : $host . '.';

        $resourceValue = $value;
        if ($type === 'CNAME' || $type === 'NS' || $type === 'MX') {
            $resourceValue = str_ends_with($value, '.') ? $value : $value . '.';
        }

        return [
            'Name' => $name,
            'Type' => $type,
            'TTL' => $ttl,
            'ResourceRecords' => [['Value' => $resourceValue]],
        ];
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
}
