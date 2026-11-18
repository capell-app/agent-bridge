<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'capell_agent_bridge_audit_entries';

    /**
     * A change made through Agent Bridge records where it can be undone from: the page it
     * changed and the page versions either side of it, never the content itself. The
     * person who reverts it and when are recorded so it is reverted only once.
     */
    public function up(): void
    {
        $table = TableName::resolve(self::TABLE);

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'revert_point')) {
            return;
        }

        Schema::table($table, static function (Blueprint $table): void {
            $table->json('revert_point')->nullable()->after('result');
            $table->string('reverted_by_type')->nullable()->after('revert_point');
            $table->unsignedBigInteger('reverted_by_id')->nullable()->after('reverted_by_type');
            $table->timestamp('reverted_at')->nullable()->after('reverted_by_id');
        });
    }

    public function down(): void
    {
        $table = TableName::resolve(self::TABLE);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'revert_point')) {
            return;
        }

        Schema::table($table, static function (Blueprint $table): void {
            $table->dropColumn(['revert_point', 'reverted_by_type', 'reverted_by_id', 'reverted_at']);
        });
    }
};
