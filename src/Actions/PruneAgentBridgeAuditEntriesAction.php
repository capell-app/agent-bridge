<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(int $days)
 */
final class PruneAgentBridgeAuditEntriesAction
{
    use AsFake;
    use AsObject;

    public function handle(int $days): int
    {
        $cutoff = CarbonImmutable::now()->subDays(max(1, $days));

        return AgentBridgeValue::integer(CapellAgentBridgeAuditEntry::query()
            ->where('created_at', '<', $cutoff)
            ->delete(), 'Pruned audit entry count');
    }
}
