<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Appends each CORE Gateway (NGGateway) request/response pair to a log file.
 * The cURL preview includes real id/atp; protect log files in production.
 */
final class NgGatewayRequestLogger
{
    public function __construct(
        private readonly string $logFilePath,
        private readonly bool $enabled = true,
    ) {
    }

    public function logExchange(
        string $requestBody,
        string $responseBody,
        string $endpoint,
        string $memberId,
        string $atp,
    ): void {
        if (!$this->enabled || $this->logFilePath === '') {
            return;
        }

        $dir = dirname($this->logFilePath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }
        }

        $ts = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        $curl = $this->buildCurlPreview($endpoint, $requestBody, $memberId, $atp);
        $block = sprintf(
            "[%s] NGGATEWAY\n--- AUTH (sensitive) ---\nid: %s\natp: %s\n--- REQUEST (payload lines) ---\n%s\n--- REQUEST (curl preview) ---\n%s\n--- RESPONSE (raw) ---\n%s\n\n",
            $ts,
            $memberId,
            $atp,
            $requestBody,
            $curl,
            $responseBody
        );

        @file_put_contents($this->logFilePath, $block, FILE_APPEND | LOCK_EX);
    }

    private function buildCurlPreview(string $endpoint, string $requestBody, string $memberId, string $atp): string
    {
        $form = [
            'action' => 'execute',
            'id' => $memberId,
            'atp' => $atp,
            '_charset_' => 'utf-8',
            'request' => $requestBody,
        ];

        $pairs = [];
        foreach ($form as $key => $value) {
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        $encodedBody = implode('&', $pairs);

        return sprintf(
            "curl -X POST %s -H %s -H %s --data %s",
            escapeshellarg($endpoint),
            escapeshellarg('accept: application/json'),
            escapeshellarg('content-type: application/x-www-form-urlencoded'),
            escapeshellarg($encodedBody)
        );
    }
}
