<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Servers;

use Capell\AgentBridge\Tools\Site\ConfirmSiteCapabilityTool;
use Capell\AgentBridge\Tools\Site\ListSiteCapabilitiesTool;
use Capell\AgentBridge\Tools\Site\RunSiteCapabilityTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Capell Site Agent Bridge')]
#[Version('1.0.0')]
#[Instructions('Authenticated, draft-only Capell MCP v1 server. Publishing is not available through this server.')]
class CapellSiteServer extends Server
{
    protected array $tools = [
        ListSiteCapabilitiesTool::class,
        RunSiteCapabilityTool::class,
        ConfirmSiteCapabilityTool::class,
    ];
}
