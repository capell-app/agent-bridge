<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Models;

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
    protected $fillable = [
        'site_id',
        'idempotency_key',
        'payload_sha256',
        'media_id',
    ];

    protected $table = 'capell_agent_bridge_media_uploads';
}
