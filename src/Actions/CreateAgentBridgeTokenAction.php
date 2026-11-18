<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Support\AgentBridgePageAccess;
use Capell\AgentBridge\Support\AgentBridgeValue;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array{token: CapellAgentBridgeToken, plainTextToken: string} run(Authenticatable $user, string $name, array<int, string> $scopes, ?DateTimeInterface $expiresAt = null, ?string $createdFromIp = null, ?array<int, int> $siteIds = null, ?array<int, string> $capabilityKeys = null, ?int $requestsPerMinute = null)
 */
final class CreateAgentBridgeTokenAction
{
    use AsFake;
    use AsObject;

    public static function defaultLifetimeDays(): int
    {
        return max(1, AgentBridgeValue::integer(config('capell-agent-bridge.token_default_lifetime_days', 90), 'Agent Bridge token default lifetime'));
    }

    public static function maximumLifetimeDays(): int
    {
        return max(self::defaultLifetimeDays(), AgentBridgeValue::integer(config('capell-agent-bridge.token_maximum_lifetime_days', 365), 'Agent Bridge token maximum lifetime'));
    }

    /**
     * @param  array<int, string>  $scopes
     * @param  array<int, int>|null  $siteIds  sites the token is limited to; null or empty for every site its owner can reach
     * @param  array<int, string>|null  $capabilityKeys  capabilities the token is limited to; null or empty for every one its scopes allow
     * @param  int|null  $requestsPerMinute  the token's own quota, applied with the site-wide limit
     * @return array{token: CapellAgentBridgeToken, plainTextToken: string}
     */
    public function handle(
        Authenticatable $user,
        string $name,
        array $scopes,
        ?DateTimeInterface $expiresAt = null,
        ?string $createdFromIp = null,
        ?array $siteIds = null,
        ?array $capabilityKeys = null,
        ?int $requestsPerMinute = null,
    ): array {
        throw_unless($user instanceof Model, InvalidArgumentException::class, 'Capell Agent Bridge tokens must be linked to an Eloquent user model.');
        throw_if($requestsPerMinute !== null && $requestsPerMinute < 1, InvalidArgumentException::class, 'An Agent Bridge token quota must be at least one request a minute.');

        $siteIds = $this->reachableSiteIds($user, $siteIds);
        $capabilityKeys = $this->registeredCapabilityKeys($capabilityKeys);
        $expiresAt = $this->boundedExpiry($expiresAt);
        $plainTextToken = CapellAgentBridgeToken::generatePlainTextToken();

        $token = new CapellAgentBridgeToken([
            'name' => $name,
            'token_hash' => CapellAgentBridgeToken::hashPlainTextToken($plainTextToken),
            'scopes' => array_values($scopes),
            'site_ids' => $siteIds,
            'capability_keys' => $capabilityKeys,
            'requests_per_minute' => $requestsPerMinute,
            'is_enabled' => true,
            // Stored as the same keyed hash as audit IPs, never the address itself.
            'created_from_ip' => HashAgentBridgeIpAddressAction::run($createdFromIp),
            'expires_at' => $expiresAt,
        ]);

        $token->user()->associate($user);
        $token->save();

        return [
            'token' => $token,
            'plainTextToken' => $plainTextToken,
        ];
    }

    /**
     * A token acts as its owner, so it never lives forever: no expiry means the default
     * lifetime, and an expiry beyond the maximum or in the past is refused.
     */
    /**
     * A limit the owner could not use is refused outright. Dropping the unreachable sites
     * instead could leave an empty list, which would create a token with no site limit.
     *
     * @param  array<int, int>|null  $siteIds
     * @return list<int>|null
     */
    private function reachableSiteIds(Authenticatable $user, ?array $siteIds): ?array
    {
        if ($siteIds === null || $siteIds === []) {
            return null;
        }

        $siteIds = array_values(array_unique(array_map(intval(...), $siteIds)));
        $reachable = AgentBridgePageAccess::authorizedSiteIds($user, 'site_ids');

        if ($reachable !== null && array_diff($siteIds, $reachable) !== []) {
            throw ValidationException::withMessages([
                'site_ids' => __('capell-agent-bridge::admin.capability_site_forbidden'),
            ]);
        }

        return $siteIds;
    }

    /**
     * @param  array<int, string>|null  $capabilityKeys
     * @return list<string>|null
     */
    private function registeredCapabilityKeys(?array $capabilityKeys): ?array
    {
        if ($capabilityKeys === null || $capabilityKeys === []) {
            return null;
        }

        $capabilityKeys = array_values(array_unique($capabilityKeys));
        $registry = resolve(CapellAgentBridgeCapabilityRegistry::class);

        foreach ($capabilityKeys as $capabilityKey) {
            if (! $registry->has($capabilityKey)) {
                throw ValidationException::withMessages([
                    'capability_keys' => __('capell-agent-bridge::admin.token_capability_unknown'),
                ]);
            }
        }

        return $capabilityKeys;
    }

    private function boundedExpiry(?DateTimeInterface $expiresAt): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if (! $expiresAt instanceof DateTimeInterface) {
            return $now->addDays(self::defaultLifetimeDays());
        }

        $expiresAt = CarbonImmutable::instance($expiresAt);

        if ($expiresAt->lessThanOrEqualTo($now) || $expiresAt->greaterThan($now->addDays(self::maximumLifetimeDays()))) {
            throw ValidationException::withMessages([
                'expires_at' => __('capell-agent-bridge::admin.token_expiry_out_of_range', ['days' => self::maximumLifetimeDays()]),
            ]);
        }

        return $expiresAt;
    }
}
