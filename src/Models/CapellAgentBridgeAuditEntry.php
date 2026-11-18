<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Models;

use Capell\AgentBridge\Data\AgentBridgeRevertPointData;
use Capell\Core\Concerns\HasCapellTable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;

/**
 * @property int $id
 * @property int|null $agent_bridge_token_id
 * @property string $event
 * @property string|null $capability_key
 * @property string|null $scope
 * @property string|null $ip_address_hash
 * @property string|null $user_agent
 * @property array<array-key, mixed>|null $payload
 * @property array<array-key, mixed>|null $result
 * @property array<array-key, mixed>|null $revert_point
 * @property string|null $reverted_by_type
 * @property int|null $reverted_by_id
 * @property CarbonImmutable|null $reverted_at
 * @property CarbonImmutable|null $created_at
 * @property Authenticatable $user
 */
final class CapellAgentBridgeAuditEntry extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'agent_bridge_token_id',
        'event',
        'capability_key',
        'scope',
        'payload',
        'result',
        'revert_point',
        'ip_address_hash',
        'user_agent',
    ];

    protected $table = 'capell_agent_bridge_audit_entries';

    /** @return BelongsTo<CapellAgentBridgeToken, $this> */
    public function agentBridgeToken(): BelongsTo
    {
        return $this->belongsTo(CapellAgentBridgeToken::class, 'agent_bridge_token_id');
    }

    /** @return MorphTo<Model, $this> */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function revertedBy(): MorphTo
    {
        return $this->morphTo('reverted_by');
    }

    /**
     * Where the recorded change can be undone from, or null when it cannot be undone
     * from here or has been undone already.
     */
    public function pendingRevertPoint(): ?AgentBridgeRevertPointData
    {
        if ($this->reverted_at !== null) {
            return null;
        }

        return AgentBridgeRevertPointData::fromStored($this->revert_point);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'revert_point' => 'array',
            'reverted_at' => 'immutable_datetime',
        ];
    }
}
