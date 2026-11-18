<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Console\Commands;

use Capell\AgentBridge\Actions\PruneAgentBridgeAuditEntriesAction;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Illuminate\Console\Command;

final class PruneAgentBridgeAuditEntriesCommand extends Command
{
    protected $signature = 'capell:agent-bridge-prune-audit {--days= : Override the configured audit retention window in days.}';

    protected $description = 'Prune Agent Bridge audit entries and expired confirmations older than the configured retention window, and confirmations that expired more than a day ago.';

    public function handle(): int
    {
        $option = $this->option('days');
        $days = is_numeric($option)
            ? (int) $option
            : AgentBridgeValue::integer(config('capell-agent-bridge.audit_retention_days', 90), 'Audit retention days');
        $result = PruneAgentBridgeAuditEntriesAction::run($days);

        $this->components->info(sprintf(
            'Pruned %d Agent Bridge audit entries and %d expired confirmations.',
            $result->auditEntries,
            $result->expiredConfirmations,
        ));

        return self::SUCCESS;
    }
}
