<?php

declare(strict_types=1);

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class CoreGatewayHttpService
{
    private const URL_EVAL = 'https://cpp-eval.corenic.net/coregw/request';
    private const URL_PROD = 'https://cpp.corenic.net/coregw/request';

    private Client $client;

    public function __construct(
        private readonly string $memberId,
        private readonly string $atp,
        private readonly NgGatewayRequestLogger $ngGatewayRequestLogger,
        bool $production = false,
        bool $verifySsl = false,
        int $timeout = 120
    ) {
        $this->client = new Client([
            'base_uri' => $production ? self::URL_PROD : self::URL_EVAL,
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'verify' => $verifySsl,
            'headers' => [
                'accept' => 'application/json',
                'content-type' => 'application/x-www-form-urlencoded',
            ],
        ]);
    }

    public function execute(string $requestBody): string
    {
        $endpoint = (string) $this->client->getConfig('base_uri');

        try {
            $response = $this->client->post('', [
                'form_params' => [
                    'action' => 'execute',
                    'id' => $this->memberId,
                    'atp' => $this->atp,
                    '_charset_' => 'utf-8',
                    'request' => $requestBody,
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->ngGatewayRequestLogger->logExchange(
                $requestBody,
                '[HTTP error] ' . $e->getMessage(),
                $endpoint,
                $this->memberId,
                $this->atp
            );
            throw new RuntimeException('CORE Gateway request failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        $body = (string) $response->getBody();
        $this->ngGatewayRequestLogger->logExchange($requestBody, $body, $endpoint, $this->memberId, $this->atp);

        return $body;
    }
}
