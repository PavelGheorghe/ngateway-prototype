<?php

declare(strict_types=1);

namespace App\Service;

class ZoneService
{
    public function __construct(
        private readonly CoreGatewayClient $client,
        private readonly CoreGatewayHelper $helper,
    ) {
    }

    public function create(array $input): array
    {
        $zoneId = trim((string) ($input['zoneId'] ?? ''));
        $domainName = trim((string) ($input['domainName'] ?? ''));
        if ($zoneId === '' || $domainName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'zoneId and domainName required'];
        }

        $payload = ['zone.id' => $zoneId, 'domain.name' => $domainName];
        $ns1 = trim((string) ($input['ns1'] ?? ''));
        $ns2 = trim((string) ($input['ns2'] ?? ''));
        if ($ns1 !== '') {
            $payload['ns.1.name'] = $ns1;
        }
        if ($ns2 !== '') {
            $payload['ns.2.name'] = $ns2;
        }
        foreach ((array) ($input['records'] ?? []) as $index => $record) {
            $payload['rr.' . ($index + 1)] = (string) $record;
        }

        return $this->helper->coreToJson($this->client->zoneCreate($payload));
    }

    public function inquire(string $zoneId): array
    {
        if ($zoneId === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'zoneId required'];
        }

        return $this->helper->coreToJson($this->client->zoneInquire($zoneId));
    }

    public function modify(array $input): array
    {
        $zoneId = trim((string) ($input['zoneId'] ?? ''));
        if ($zoneId === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'zoneId required'];
        }

        $records = [];
        foreach ((array) ($input['records'] ?? []) as $record) {
            if (is_string($record) && $record !== '') {
                $records[] = $record;
            } elseif (is_array($record) && isset($record['type'], $record['name'], $record['value'])) {
                $records[] = $record['name'] . ' IN ' . $record['type'] . ' ' . $record['value'];
            }
        }

        return $this->helper->coreToJson($this->client->zoneModify($zoneId, $records));
    }

    public function delete(array $input): array
    {
        $zoneId = trim((string) ($input['zoneId'] ?? ''));
        if ($zoneId === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'zoneId required'];
        }

        return $this->helper->coreToJson($this->client->zoneDelete($zoneId));
    }
}
