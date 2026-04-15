<?php

declare(strict_types=1);

namespace App\Service;

class PollService
{
    public function __construct(
        private readonly CoreGatewayClient $client,
        private readonly CoreGatewayHelper $helper,
    ) {
    }

    /**
     * poll.request — fetch next message from the member poll queue.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function request(array $input): array
    {
        $extra = [];
        $msgId = trim((string) ($input['msgId'] ?? ''));
        if ($msgId !== '') {
            $extra['msg.id'] = $msgId;
        }
        if (array_key_exists('msgAutoack', $input)) {
            $extra['msg.autoack'] = filter_var($input['msgAutoack'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }

        return $this->helper->coreToJson($this->client->pollRequest($extra));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function acknowledge(array $input): array
    {
        $msgId = trim((string) ($input['msgId'] ?? ''));
        if ($msgId === '') {
            return ['success' => false, 'code' => 'invalid', 'message' => 'msgId required'];
        }

        return $this->helper->coreToJson($this->client->pollAcknowledge($msgId));
    }

    /**
     * poll.status — summary of stored poll messages (ids / unread head).
     *
     * @param array<string, mixed> $input Optional msgAfter, msgBefore (ISO-8601, maps to msg.after / msg.before)
     *
     * @return array<string, mixed>
     */
    public function status(array $input): array
    {
        $extra = [];
        $after = trim((string) ($input['msgAfter'] ?? $input['msg.after'] ?? ''));
        $before = trim((string) ($input['msgBefore'] ?? $input['msg.before'] ?? ''));
        if ($after !== '') {
            $extra['msg.after'] = $after;
        }
        if ($before !== '') {
            $extra['msg.before'] = $before;
        }

        return $this->helper->coreToJson($this->client->pollStatus($extra));
    }
}
