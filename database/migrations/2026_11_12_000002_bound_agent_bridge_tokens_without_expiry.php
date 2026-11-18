<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'capell_agent_bridge_tokens';

    /**
     * Tokens created before expiry became required could have no expiry and never stop
     * working. Each one now expires after the default token lifetime, counted from this
     * upgrade, so connected clients keep working until then rather than failing at once.
     */
    public function up(): void
    {
        $table = TableName::resolve(self::TABLE);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'expires_at')) {
            return;
        }

        DB::table($table)
            ->whereNull('expires_at')
            ->update(['expires_at' => now()->addDays(CreateAgentBridgeTokenAction::defaultLifetimeDays())]);
    }

    /**
     * @migration-forward-only Which tokens had no expiry is not recorded, and making them
     * unlimited again would undo the bound this migration exists to add.
     */
    public function down(): void {}
};
