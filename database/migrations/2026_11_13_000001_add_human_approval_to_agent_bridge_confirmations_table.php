<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'capell_agent_bridge_confirmations';

    /**
     * High-risk capabilities wait for the person behind the token to approve them in the
     * admin before the agent can confirm. The approval is recorded on the confirmation row,
     * which is already bound to that person, the capability and the payload hash.
     */
    public function up(): void
    {
        $table = TableName::resolve(self::TABLE);

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'requires_approval')) {
            return;
        }

        Schema::table($table, static function (Blueprint $table): void {
            $table->boolean('requires_approval')->default(false)->after('preview');
            $table->string('approved_by_type')->nullable()->after('requires_approval');
            $table->unsignedBigInteger('approved_by_id')->nullable()->after('approved_by_type');
            $table->timestamp('approved_at')->nullable()->after('approved_by_id');
            $table->timestamp('declined_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        $table = TableName::resolve(self::TABLE);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'requires_approval')) {
            return;
        }

        Schema::table($table, static function (Blueprint $table): void {
            $table->dropColumn(['requires_approval', 'approved_by_type', 'approved_by_id', 'approved_at', 'declined_at']);
        });
    }
};
