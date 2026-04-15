<?php

declare(strict_types=1);

namespace App\Service;

class CoreGatewayHelper
{
    public function addProviderChain(array $payload, string $spec, string $type, bool $skipProviderChain): array
    {
        if ($skipProviderChain || $spec === '') {
            return $payload;
        }

        $payload['provider.chain.1.spec'] = $spec;
        $payload['provider.chain.1.type'] = $type;

        return $payload;
    }

    public function normalizeContactId(string $id): string
    {
        $id = preg_replace('/[^\x20-\x7E]/', '', $id) ?? '';
        $id = trim($id);
        if ($id === '' || strlen($id) > 16) {
            return 'c' . substr(bin2hex(random_bytes(5)), 0, 10);
        }
        if (strlen($id) < 3) {
            return $id . substr(bin2hex(random_bytes(2)), 0, 3 - strlen($id));
        }

        return $id;
    }

    public function normalizeDomainCheckResponse(array $response): array
    {
        $code = trim((string) ($response['result.code'] ?? ''));
        $success = $code === '10000';
        $response['result.code'] = $code;

        if (isset($response['domain.name']) && empty($response['domain.1.name'])) {
            $response['domain.1.name'] = $response['domain.name'];
        }

        if (isset($response['domain.avail']) && empty($response['domain.1.available'])) {
            $response['domain.1.available'] = $response['domain.avail'];
        }

        $n = 1;
        while (!empty($response["domain.{$n}.name"])) {
            $name = $response["domain.{$n}.name"];
            if (is_array($name)) {
                $response["domain.{$n}.name"] = $name[0] ?? '';
            }

            if ((string) ($response["domain.{$n}.available"] ?? '') === '' && $success) {
                $response["domain.{$n}.available"] = '1';
            }
            $n++;
        }

        return $response;
    }

    public function filterEmpty(array $payload): array
    {
        $filtered = [];
        foreach ($payload as $key => $value) {
            if (is_string($value) && $value !== '') {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    public function coreToJson(array $response): array
    {
        $success = CoreGatewayClient::isSuccess($response);
        $subresults = [];
        foreach ($response as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (preg_match('/^result\.(\d+)\.(code|msg)$/', $key, $matches)) {
                $subresults[(int) $matches[1]][$matches[2]] = $value;
            }
        }
        ksort($subresults);

        $payload = [
            'success' => $success,
            'code' => CoreGatewayClient::getResultCode($response),
            'message' => CoreGatewayClient::getResultMessage($response),
            'data' => $response,
        ];
        if ($subresults !== []) {
            $payload['subresults'] = array_values($subresults);
        }

        return $payload;
    }
}
