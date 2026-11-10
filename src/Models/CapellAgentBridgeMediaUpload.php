<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Models;

use Capell\Core\Concerns\HasCapellTable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $site_id
 * @property string $idempotency_key
 * @property string $payload_sha256
 * @property int|null $media_id
 */
final class CapellAgentBridgeMediaUpload extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'site_id',
        'idempotency_key',
        'payload_sha256',
        'media_id',
    ];

    protected $table = 'capell_agent_bridge_media_uploads';
}
