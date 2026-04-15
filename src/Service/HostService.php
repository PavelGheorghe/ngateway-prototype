<?php

declare(strict_types=1);

namespace App\Service;

final class HostService
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

        $hostName = strtolower(trim((string) ($input['hostName'] ?? '')));
        if ($hostName === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'hostName required'];
        }

        $ipv4 = trim((string) ($input['ipv4'] ?? ''));
        $ipv6 = trim((string) ($input['ipv6'] ?? ''));
        if ($ipv4 === '' && $ipv6 === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'ipv4 or ipv6 required'];
        }

        $payload = [
            'host.name' => $hostName,
        ];

        $addressIndex = 1;
        if ($ipv4 !== '') {
            $payload['host.addr.' . $addressIndex . '.value'] = $ipv4;
            $payload['host.addr.' . $addressIndex . '.type'] = 'ipv4';
            $addressIndex++;
        }
        if ($ipv6 !== '') {
            $payload['host.addr.' . $addressIndex . '.value'] = $ipv6;
            $payload['host.addr.' . $addressIndex . '.type'] = 'ipv6';
        }

        $authInfo = trim((string) ($input['authInfo'] ?? ''));
        if ($authInfo !== '') {
            $payload['host.authinfo'] = $authInfo;
        }

        $payload = $this->helper->addProviderChain($payload, $this->providerChainSpec, $this->providerChainType, $this->skipProviderChain);

        return $this->helper->coreToJson($this->client->hostCreate($registryId, $payload));
    }
}
