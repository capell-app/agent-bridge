<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Servers;

use Capell\AgentBridge\Actions\AuthenticateLocalAgentBridgeClientAction;

final class CapellLocalSiteServer extends CapellSiteServer
{
    protected function boot(): void
    {
        AuthenticateLocalAgentBridgeClientAction::run();
    }
}
