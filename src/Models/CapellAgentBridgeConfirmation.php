<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Models;

use Capell\Core\Concerns\HasCapellTable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;
use RuntimeException;

/**
 * @property int $id
 * @property string $token
 * @property int $agent_bridge_token_id
 * @property string|null $user_type
 * @property int|null $user_id
 * @property string $capability_key
 * @property string $scope
 * @property string $payload_hash
 * @property array<array-key, mixed> $payload
 * @property array<array-key, mixed> $preview
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property Authenticatable $user
 * @property CapellAgentBridgeToken $agentBridgeToken
 */
final class CapellAgentBridgeConfirmation extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'token',
        'agent_bridge_token_id',
        'capability_key',
        'scope',
        'payload_hash',
        'payload',
        'preview',
        'expires_at',
        'used_at',
    ];

    protected $table = 'capell_agent_bridge_confirmations';

    public static function hashPlainTextToken(string $plainTextToken): string
    {
        $applicationKey = config('app.key');

        throw_unless(is_string($applicationKey) && $applicationKey !== '', RuntimeException::class, 'The application key is required to hash Agent Bridge confirmation tokens.');

        return hash_hmac('sha256', $plainTextToken, $applicationKey);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

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

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'preview' => 'array',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }
}
