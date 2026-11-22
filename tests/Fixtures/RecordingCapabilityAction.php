<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tests\Fixtures;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;

final class RecordingCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public static int $previews = 0;

    public static int $executions = 0;

    public static function reset(): void
    {
        self::$previews = 0;
        self::$executions = 0;
    }

    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        self::$previews++;

        return new CapabilityResultData(ok: true, message: 'Previewed recording capability.', data: ['payload' => $invocation->payload]);
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        self::$executions++;

        return new CapabilityResultData(ok: true, message: 'Executed recording capability.', data: ['payload' => $invocation->payload]);
    }
}
