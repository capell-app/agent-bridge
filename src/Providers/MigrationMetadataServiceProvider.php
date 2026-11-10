<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Providers;

use Capell\Core\Support\Migration\MigrationRootRegistry;
use Illuminate\Support\ServiceProvider;
use Override;

final class MigrationMetadataServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {

        $this->callAfterResolving(MigrationRootRegistry::class, static function (MigrationRootRegistry $registry): void {
            $registry->register('capell-app/agent-bridge', __DIR__ . '/../../database/migrations', [
                '2026_05_10_190840_01_create_capell_agent-bridge_tokens_table' => 'capell_agent_bridge_tokens',
                '2026_05_10_190840_02_create_capell_agent-bridge_confirmations_table' => 'capell_agent_bridge_confirmations',
                '2026_05_10_190840_03_create_capell_agent-bridge_audit_entries_table' => 'capell_agent_bridge_audit_entries',
                '2026_05_27_000001_create_capell_agent-bridge_saved_prompts_table' => 'capell_agent_bridge_saved_prompts',
                '2026_07_20_000001_create_capell_agent_bridge_media_uploads_table' => 'capell_agent_bridge_media_uploads',
            ]);
        });
    }
}
