<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tests\Fixtures;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgeTokenSiteRestriction;
use Illuminate\Validation\ValidationException;

/**
 * Behaves like a capability run for a global administrator: every site is reachable
 * unless the running token is limited to some.
 */
final class SiteScopedFakeCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return $this->result($invocation, 'Previewed site change.');
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return $this->result($invocation, 'Changed site.');
    }

    private function result(CapabilityInvocationData $invocation, string $message): CapabilityResultData
    {
        $siteId = $invocation->payload['site_id'] ?? null;
        $allowed = AgentBridgeTokenSiteRestriction::narrow(null);

        if (! is_int($siteId) || ($allowed !== null && ! in_array($siteId, $allowed, true))) {
            throw ValidationException::withMessages(['site_id' => 'This Agent Bridge token is not allowed to use the selected site.']);
        }

        return new CapabilityResultData(ok: true, message: $message, data: ['site_id' => $siteId]);
    }
}
