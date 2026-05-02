<?php

declare(strict_types=1);

use Capell\Mcp\Http\Middleware\AuthenticateCapellMcpToken;
use Capell\Mcp\Servers\CapellKnowledgeServer;
use Capell\Mcp\Servers\CapellSiteServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web(config('capell-mcp.routes.knowledge', 'mcp/capell/knowledge'), CapellKnowledgeServer::class);

Mcp::web(config('capell-mcp.routes.site', 'mcp/capell/site'), CapellSiteServer::class)
    ->middleware(AuthenticateCapellMcpToken::class);
