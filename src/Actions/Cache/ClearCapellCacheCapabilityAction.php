<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions\Cache;

use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;
use Capell\AgentBridge\Support\AgentBridgePolicyAuthorizer;
use Illuminate\Support\Facades\Artisan;

final class ClearCapellCacheCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        resolve(AgentBridgePolicyAuthorizer::class)->authorizeGlobalAdmin($invocation->user);

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_cache_clear_preview'),
            data: [
                'commands' => $this->availableCommands(),
            ],
        );
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        resolve(AgentBridgePolicyAuthorizer::class)->authorizeGlobalAdmin($invocation->user);

        $results = [];

        foreach ($this->availableCommands() as $command) {
            Artisan::call($command);
            $results[$command] = trim(Artisan::output());
        }

        return new CapabilityResultData(
            ok: true,
            message: (string) __('capell-agent-bridge::admin.result_cache_cleared'),
            data: [
                'results' => $results,
            ],
        );
    }

    /** @return array<int, string> */
    private function availableCommands(): array
    {
        $commands = [
            'cache:clear',
            'config:clear',
            'view:clear',
            'capell:clear-components-cache',
            'capell:admin-clear-cache',
            'capell:admin-clear-widgets-cache',
            'capell:admin-clear-configurators-cache',
            'capell:package-cache:clear',
        ];

        $available = array_keys(Artisan::all());

        return array_values(array_intersect($commands, $available));
    }
}
