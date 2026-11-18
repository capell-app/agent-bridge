<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Data\AgentBridgePruneResultData;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static AgentBridgePruneResultData run(int $days)
 */
final class PruneAgentBridgeAuditEntriesAction
{
    use AsFake;
    use AsObject;

    /**
     * How long an expired confirmation is kept. It can no longer be confirmed, and it may
     * hold a full payload written by an older release, so it does not follow the
     * audit retention window.
     */
    public const int EXPIRED_CONFIRMATION_GRACE_DAYS = 1;

    public function handle(int $days): AgentBridgePruneResultData
    {
        $now = CarbonImmutable::now();

        $auditEntries = AgentBridgeValue::integer(CapellAgentBridgeAuditEntry::query()
            ->where('created_at', '<', $now->subDays(max(1, $days)))
            ->delete(), 'Pruned audit entry count');

        $confirmations = AgentBridgeValue::integer(CapellAgentBridgeConfirmation::query()
            ->where('expires_at', '<', $now->subDays(self::EXPIRED_CONFIRMATION_GRACE_DAYS))
            ->delete(), 'Pruned confirmation count');

        return new AgentBridgePruneResultData($auditEntries, $confirmations);
    }
}
