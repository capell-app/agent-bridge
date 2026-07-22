<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capell_agent_bridge_media_uploads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->uuid('idempotency_key');
            $table->char('payload_sha256', 64);
            $table->unsignedBigInteger('media_id')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'idempotency_key'], 'agent_bridge_media_upload_site_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capell_agent_bridge_media_uploads');
    }
};
