<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\HashAgentBridgeIpAddressAction;
use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'capell_agent_bridge_tokens';

    /**
     * Replaces each plaintext creation IP with the keyed hash used for audit IPs. A value
     * that is already a hash is left alone, so the migration can run more than once.
     */
    public function up(): void
    {
        $table = TableName::resolve(self::TABLE);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'created_from_ip')) {
            return;
        }

        DB::table($table)
            ->select(['id', 'created_from_ip'])
            ->whereNotNull('created_from_ip')
            ->orderBy('id')
            ->chunkById(500, static function (Collection $rows) use ($table): void {
                foreach ($rows as $row) {
                    $value = is_string($row->created_from_ip) ? $row->created_from_ip : '';

                    if (preg_match('/\A[0-9a-f]{64}\z/', $value) === 1) {
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['created_from_ip' => HashAgentBridgeIpAddressAction::run($value)]);
                }
            });
    }

    /**
     * @migration-forward-only The plaintext addresses cannot be recovered from their hashes,
     * and restoring them is exactly what this migration exists to prevent.
     */
    public function down(): void {}
};
