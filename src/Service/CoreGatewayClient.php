<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

class CoreGatewayClient
{
    private const PAYLOAD_VERSION = '2.0';
    private const MEMBER_ID_PATTERN = '/^CORE-\d{2,25}$/i';

    private string $memberId;
    private string $clientName;

    public function __construct(
        string $memberId,
        string $clientName = 'puntu-api',
        private readonly CoreGatewayHttpService $httpService
    )
    {
        if (!preg_match(self::MEMBER_ID_PATTERN, $memberId)) {
            throw new InvalidArgumentException('Invalid CORE Member ID; expected pattern CORE-<2-25 digits>.');
        }

        $this->memberId = $memberId;
        $this->clientName = $clientName !== '' ? $clientName : 'puntu-api';
    }

    public function send(array $payload): array
    {
        $raw = $this->sendRaw($payload);

        return self::parseResponse($raw);
    }

    public function domainCheck(array $domainNames, string $registryId, array $extra = []): array
    {
        $payload = ['request.type' => 'domain.check', 'registry.id' => $registryId];
        foreach ($domainNames as $index => $name) {
            $payload['domain.' . ($index + 1) . '.name'] = self::fqdnForRegistry((string) $name, $registryId);
        }

        return $this->send(array_merge($payload, $extra));
    }

    public function domainCreate(string $registryId, string $domainName, array $extra = []): array
    {
        return $this->send(array_merge([
            'request.type' => 'domain.create',
            'registry.id' => $registryId,
            'domain.name' => self::fqdnForRegistry($domainName, $registryId),
        ], $extra));
    }

    public function domainInquire(string $registryId, string $domainName): array
    {
        return $this->send([
            'request.type' => 'domain.inquire',
            'registry.id' => $registryId,
            'domain.name' => self::fqdnForRegistry($domainName, $registryId),
        ]);
    }

    /**
     * domain.inquire with domain.authinfo (transfer / auth validation per registry).
     */
    public function domainInquireWithAuth(string $registryId, string $domainName, string $domainAuthinfo): array
    {
        return $this->send([
            'request.type' => 'domain.inquire',
            'registry.id' => $registryId,
            'domain.name' => self::fqdnForRegistry($domainName, $registryId),
            'domain.authinfo' => $domainAuthinfo,
        ]);
    }

    /**
     * Retrieve current domain authorization (transfer) code for domains sponsored by this member.
     */
    public function domainAuthinfoRequest(string $registryId, string $domainName, array $extra = []): array
    {
        return $this->send(array_merge([
            'request.type' => 'domain.authinfo.request',
            'registry.id' => $registryId,
            'domain.name' => self::fqdnForRegistry($domainName, $registryId),
        ], $extra));
    }

    /**
     * Gaining-registrar transfer initiation (Payload 2.0).
     */
    public function domainTransferRequest(string $registryId, string $domainName, array $extra = []): array
    {
        return $this->send(array_merge([
            'request.type' => 'domain.transfer.request',
            'registry.id' => $registryId,
            'domain.name' => self::fqdnForRegistry($domainName, $registryId),
        ], $extra));
    }

    /**
     * Losing-registrar response to a pending transfer (approve / reject).
     */
    public function domainTransferReply(string $registryId, string $domainName, array $extra = []): array
    {
        return $this->send(array_merge([
            'request.type' => 'domain.transfer.reply',
            'registry.id' => $registryId,
            'domain.name' => self::fqdnForRegistry($domainName, $registryId),
        ], $extra));
    }

    public function pollRequest(array $extra = []): array
    {
        return $this->send(array_merge([
            'request.type' => 'poll.request',
        ], $extra));
    }

    public function pollAcknowledge(string $msgId): array
    {
        return $this->send([
            'request.type' => 'poll.acknowledge',
            'msg.id' => $msgId,
        ]);
    }

    /**
     * poll.status — queue bounds (msg.first.id, msg.unread.id, msg.last.id) per Payload 2.0.
     *
     * @param array<string, string> $extra Optional msg.after / msg.before (ISO-8601 Z timestamps)
     */
    public function pollStatus(array $extra = []): array
    {
        return $this->send(array_merge([
            'request.type' => 'poll.status',
        ], $extra));
    }

    public function domainRenew(string $registryId, string $domainName, string $periodUnit = 'y', int $periodValue = 1): array
    {
        return $this->send([
            'request.type' => 'domain.renew',
            'registry.id' => $registryId,
            'domain.name' => self::fqdnForRegistry($domainName, $registryId),
            'period.unit' => $periodUnit,
            'period.value' => (string) $periodValue,
        ]);
    }

    public function domainDelete(string $registryId, string $domainName): array
    {
        return $this->send([
            'request.type' => 'domain.delete',
            'registry.id' => $registryId,
            'domain.name' => self::fqdnForRegistry($domainName, $registryId),
        ]);
    }

    /**
     * CORE Payload 2.0 domain.status.modify: supply domain.name and/or domain.id (at least one required).
     * The bundled spec example sends domain.name, domain.name.i15d, domain.id, domain.status.remove, and
     * domain.status.add together; this client includes domain.name whenever a name is provided, even when
     * domain.id is also present.
     *
     * @param array<string, mixed> $extra domain.status.add/remove, optional domain.id, domain.name.i15d, transaction.otp/atp, effective.member.id, …
     */
    public function domainStatusModify(string $registryId, string $domainName, array $extra = []): array
    {
        $domainId = trim((string) ($extra['domain.id'] ?? ''));
        $i15d = trim((string) ($extra['domain.name.i15d'] ?? ''));

        $merged = $extra;
        unset($merged['domain.id'], $merged['domain.name.i15d']);

        $payload = [
            'request.type' => 'domain.status.modify',
            'registry.id' => $registryId,
        ];

        $dn = trim($domainName);
        $fqdn = $dn !== '' ? self::fqdnForRegistry($domainName, $registryId) : '';
        if ($fqdn === '' && $domainId === '') {
            throw new InvalidArgumentException('domain.status.modify requires domain.name or domain.id.');
        }
        if ($fqdn !== '') {
            $payload['domain.name'] = $fqdn;
        }
        if ($domainId !== '') {
            $payload['domain.id'] = $domainId;
        }
        if ($i15d !== '') {
            $payload['domain.name.i15d'] = $i15d;
        }

        return $this->send(array_merge($payload, $merged));
    }

    public function domainModify(string $registryId, string $domainName, array $extra = []): array
    {
        return $this->send(array_merge([
            'request.type' => 'domain.modify',
            'registry.id' => $registryId,
            'domain.name' => self::fqdnForRegistry($domainName, $registryId),
        ], $extra));
    }

    /**
     * Payload 2.0 expects domain.name as FQDN (including TLD). Align with domain.check by appending
     * registry.id when the name is not already suffixed (e.g. doteus rejects a bare SLD).
     */
    public static function fqdnForRegistry(string $domainName, string $registryId): string
    {
        $domainName = strtolower(trim($domainName));
        $domainName = rtrim($domainName, '.');
        $registryId = trim($registryId);
        if ($domainName === '') {
            return '';
        }
        if ($registryId === '') {
            return $domainName;
        }
        if ($registryId[0] !== '.') {
            $registryId = '.' . $registryId;
        }
        $suffix = strtolower($registryId);

        return str_ends_with($domainName, $suffix) ? $domainName : $domainName . $suffix;
    }

    public function contactCreate(string $registryId, array $extra): array
    {
        return $this->send(array_merge([
            'request.type' => 'contact.create',
            'registry.id' => $registryId,
        ], $extra));
    }

    public function contactInquire(string $registryId, string $contactId): array
    {
        return $this->send([
            'request.type' => 'contact.inquire',
            'registry.id' => $registryId,
            'contact.id' => $contactId,
        ]);
    }

    public function contactModify(string $registryId, string $contactId, array $extra = []): array
    {
        return $this->send(array_merge([
            'request.type' => 'contact.modify',
            'registry.id' => $registryId,
            'contact.id' => $contactId,
        ], $extra));
    }

    public function hostCreate(string $registryId, array $extra): array
    {
        return $this->send(array_merge([
            'request.type' => 'host.create',
            'registry.id' => $registryId,
        ], $extra));
    }

    public function zoneCreate(array $extra): array
    {
        return $this->send(array_merge(['request.type' => 'zone.create'], $extra));
    }

    public function zoneInquire(string $zoneId): array
    {
        return $this->send([
            'request.type' => 'zone.inquire',
            'zone.id' => $zoneId,
        ]);
    }

    public function zoneModify(string $zoneId, array $records): array
    {
        $payload = [
            'request.type' => 'zone.modify',
            'zone.id' => $zoneId,
        ];

        foreach ($records as $index => $rr) {
            $payload['rr.' . ($index + 1)] = (string) $rr;
        }

        return $this->send($payload);
    }

    public function zoneDelete(string $zoneId): array
    {
        return $this->send([
            'request.type' => 'zone.delete',
            'zone.id' => $zoneId,
        ]);
    }

    public static function isSuccess(array $response): bool
    {
        return (string) ($response['result.code'] ?? '') === '10000';
    }

    public static function getResultCode(array $response): ?string
    {
        return isset($response['result.code']) ? (string) $response['result.code'] : null;
    }

    public static function getResultMessage(array $response): ?string
    {
        return isset($response['result.msg']) ? (string) $response['result.msg'] : null;
    }

    private function sendRaw(array $payload): string
    {
        $body = $this->buildPayload($payload);

        return $this->httpService->execute($body);
    }

    private function buildPayload(array $payload): string
    {
        $lines = [];
        foreach ($payload as $key => $value) {
            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                continue;
            }

            $stringValue = str_replace(["\r\n", "\r", "\n"], ' ', $stringValue);
            $lines[] = $key . ': ' . $stringValue;
        }

        $lines[] = 'core.member.id: ' . $this->memberId;
        $lines[] = 'payload.version: ' . self::PAYLOAD_VERSION;
        $lines[] = 'transaction.id: ' . $this->clientName . '-' . $this->memberId . '-' . uniqid('', true);
        $lines[] = 'machine.user.id: ' . $this->clientName;

        return implode("\r\n", $lines);
    }

    private static function parseResponse(string $raw): array
    {
        $output = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($lines as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            if (!isset($output[$key])) {
                $output[$key] = $value;
            } elseif (is_array($output[$key])) {
                $output[$key][] = $value;
            } else {
                $output[$key] = [$output[$key], $value];
            }
        }

        return $output;
    }
}
