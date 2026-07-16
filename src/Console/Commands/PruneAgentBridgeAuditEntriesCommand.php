<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Console\Commands;

use Capell\AgentBridge\Actions\PruneAgentBridgeAuditEntriesAction;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Illuminate\Console\Command;

final class PruneAgentBridgeAuditEntriesCommand extends Command
{
    protected $signature = 'capell:agent-bridge-prune-audit {--days= : Override the configured audit retention window in days.}';

    protected $description = 'Prune Agent Bridge audit entries older than the configured retention window.';

    public function handle(): int
    {
        $option = $this->option('days');
        $days = is_numeric($option)
            ? (int) $option
            : AgentBridgeValue::integer(config('capell-agent-bridge.audit_retention_days', 90), 'Audit retention days');
        $deleted = PruneAgentBridgeAuditEntriesAction::run($days);

        $this->components->info(sprintf('Pruned %d Agent Bridge audit entr%s.', $deleted, $deleted === 1 ? 'y' : 'ies'));

        return self::SUCCESS;
    }
}
