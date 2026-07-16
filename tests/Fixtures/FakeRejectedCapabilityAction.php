<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tests\Fixtures;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;

final class FakeRejectedCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return new CapabilityResultData(
            ok: false,
            message: 'The fake capability is not ready.',
            data: ['payload' => $invocation->payload],
            warnings: ['Resolve the fake warning before continuing.'],
        );
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return new CapabilityResultData(
            ok: true,
            message: 'Executed fake capability.',
            data: ['payload' => $invocation->payload],
        );
    }
}
