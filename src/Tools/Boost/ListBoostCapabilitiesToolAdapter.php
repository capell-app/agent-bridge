<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Tools\Boost;

use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('capell-list-capabilities')]
#[Title('List Capell Capabilities')]
#[Description('List installed Capell Agent Bridge capabilities available through the Laravel Boost Agent Bridge server.')]
#[IsReadOnly]
final class ListBoostCapabilitiesToolAdapter extends Tool
{
    public function handle(Request $request): Response
    {
        return (new ListBoostCapabilitiesTool)->listCapabilities(resolve(CapellAgentBridgeCapabilityRegistry::class))
            ->responses()->sole();
    }
}
