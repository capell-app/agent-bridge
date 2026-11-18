<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data;

use Spatie\LaravelData\Data;

final class CapabilityResultData extends Data
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $warnings
     * @param  AgentBridgeRevertPointData|null  $revertPoint  where the change can be undone from; kept in the audit log and never sent to the client
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $data = [],
        public readonly array $warnings = [],
        public readonly ?AgentBridgeRevertPointData $revertPoint = null,
    ) {}

    /**
     * @return array{ok: bool, message: string, data: array<string, mixed>, warnings: array<int, string>}
     */
    public function toPayload(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'data' => $this->data,
            'warnings' => $this->warnings,
        ];
    }
}
