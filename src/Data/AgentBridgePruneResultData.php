<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data;

final readonly class AgentBridgePruneResultData
{
    public function __construct(
        public int $auditEntries,
        public int $expiredConfirmations,
    ) {}
}
