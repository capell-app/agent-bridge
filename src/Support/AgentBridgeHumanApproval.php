<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\AgentBridge\Data\AuthenticatedAgentBridgeClientData;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;

/**
 * Decides which previewed capabilities wait for a person to approve them in the admin.
 *
 * A bridge token is held by an agent, and anything the agent reads can steer it, so the
 * token alone must not be able to both preview and confirm a high-risk change. Approval
 * happens in the admin session of the person the token acts as, never over the bridge.
 */
final class AgentBridgeHumanApproval
{
    /** @var list<string> */
    public const array DEFAULT_RISKS = ['high', 'destructive'];

    public static function requiredFor(CapabilityData $capability): bool
    {
        return $capability->needsConfirmation() && in_array($capability->risk, self::risks(), true);
    }

    /** @return list<CapabilityRiskEnum> */
    public static function risks(): array
    {
        $configured = config('capell-agent-bridge.human_approval_risks', self::DEFAULT_RISKS);

        if (! is_array($configured)) {
            $configured = self::DEFAULT_RISKS;
        }

        $risks = [];

        foreach ($configured as $value) {
            $risk = is_string($value) ? CapabilityRiskEnum::tryFrom($value) : ($value instanceof CapabilityRiskEnum ? $value : null);

            if ($risk instanceof CapabilityRiskEnum && ! in_array($risk, $risks, true)) {
                $risks[] = $risk;
            }
        }

        return $risks;
    }

    public static function approvalTtlMinutes(): int
    {
        return max(1, AgentBridgeValue::integer(
            config('capell-agent-bridge.approval_ttl_minutes', 60),
            'Agent Bridge approval TTL',
        ));
    }

    /**
     * True while a bridge token request is being served. Approval is a person's decision
     * in the admin, so it is refused from inside any bridge request, whatever calls it.
     */
    public static function insideBridgeRequest(): bool
    {
        return app()->bound(AuthenticatedAgentBridgeClientData::class);
    }
}
