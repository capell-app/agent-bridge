<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Models;

use Capell\AgentBridge\Enums\AgentBridgeTokenLifecycleStatus;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\Core\Concerns\HasCapellTable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Override;

/**
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property string $user_type
 * @property int $user_id
 * @property array<int, string> $scopes
 * @property bool $is_enabled
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $rotated_at
 * @property string|null $created_from_ip
 * @property list<int>|null $site_ids
 * @property list<string>|null $capability_keys
 * @property int|null $requests_per_minute
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property-read AgentBridgeTokenLifecycleStatus $lifecycle_status
 * @property Authenticatable|null $user
 */
final class CapellAgentBridgeToken extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'name',
        'token_hash',
        'scopes',
        'site_ids',
        'capability_keys',
        'requests_per_minute',
        'is_enabled',
        'created_from_ip',
        'expires_at',
    ];

    protected $table = 'capell_agent_bridge_tokens';

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_enabled' => true,
    ];

    public static function hashPlainTextToken(string $plainTextToken): string
    {
        return hash_hmac(
            'sha256',
            $plainTextToken,
            AgentBridgeValue::nonEmptyString(config('app.key'), 'Application key'),
        );
    }

    public static function legacyHashPlainTextToken(string $plainTextToken): string
    {
        return hash('sha256', $plainTextToken);
    }

    public static function findForPlainTextToken(string $plainTextToken): ?self
    {
        $token = self::query()
            ->where('token_hash', 'like', substr(self::hashPlainTextToken($plainTextToken), 0, 16) . '%')
            ->get()->first(static fn (self $candidate): bool => hash_equals($candidate->token_hash, self::hashPlainTextToken($plainTextToken)));

        if ($token instanceof self) {
            return $token;
        }

        if (! (bool) config('capell-agent-bridge.accept_legacy_token_hashes', false)) {
            return null;
        }

        $legacyToken = self::query()
            ->where('token_hash', 'like', substr(self::legacyHashPlainTextToken($plainTextToken), 0, 16) . '%')
            ->get()->first(static fn (self $candidate): bool => hash_equals($candidate->token_hash, self::legacyHashPlainTextToken($plainTextToken)));

        return $legacyToken instanceof self ? $legacyToken : null;
    }

    public static function generatePlainTextToken(): string
    {
        return AgentBridgeValue::string(
            config('capell-agent-bridge.token_prefix', 'cagent-bridge_'),
            'Agent Bridge token prefix',
        ) . Str::random(48);
    }

    public function hasLegacyHashForPlainTextToken(string $plainTextToken): bool
    {
        return hash_equals($this->token_hash, self::legacyHashPlainTextToken($plainTextToken));
    }

    public function canUseScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true) || in_array('*', $this->scopes, true);
    }

    /**
     * Null means every capability its scopes allow; a list narrows it to those keys.
     */
    public function canUseCapability(string $capabilityKey): bool
    {
        return $this->capability_keys === null || in_array($capabilityKey, $this->capability_keys, true);
    }

    /**
     * Null means every site its owner can reach; a list narrows it to those sites.
     *
     * @return list<int>|null
     */
    public function restrictedSiteIds(): ?array
    {
        if ($this->site_ids === null) {
            return null;
        }

        $siteIds = [];

        foreach ($this->site_ids as $siteId) {
            if (is_int($siteId) || (is_string($siteId) && ctype_digit($siteId))) {
                $siteIds[] = (int) $siteId;
            }
        }

        return array_values(array_unique($siteIds));
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof CarbonImmutable && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at instanceof CarbonImmutable;
    }

    public function isUsable(): bool
    {
        return $this->is_enabled && ! $this->isRevoked() && ! $this->isExpired();
    }

    public function lifecycleStatus(): AgentBridgeTokenLifecycleStatus
    {
        if ($this->isRevoked() || ! $this->is_enabled) {
            return AgentBridgeTokenLifecycleStatus::Revoked;
        }

        if ($this->isExpired()) {
            return AgentBridgeTokenLifecycleStatus::Expired;
        }

        return AgentBridgeTokenLifecycleStatus::Active;
    }

    /** @return MorphTo<Model, $this> */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    protected function getLifecycleStatusAttribute(): AgentBridgeTokenLifecycleStatus
    {
        return $this->lifecycleStatus();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeWithLifecycleStatus(Builder $query, AgentBridgeTokenLifecycleStatus $status): Builder
    {
        return match ($status) {
            AgentBridgeTokenLifecycleStatus::Active => $query
                ->where('is_enabled', true)
                ->whereNull('revoked_at')
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                }),
            AgentBridgeTokenLifecycleStatus::Expired => $query
                ->where('is_enabled', true)
                ->whereNull('revoked_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()),
            AgentBridgeTokenLifecycleStatus::Revoked => $query
                ->where(function (Builder $query): void {
                    $query
                        ->where('is_enabled', false)
                        ->orWhereNotNull('revoked_at');
                }),
        };
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'site_ids' => 'array',
            'capability_keys' => 'array',
            'requests_per_minute' => 'integer',
            'is_enabled' => 'boolean',
            'revoked_at' => 'immutable_datetime',
            'rotated_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
