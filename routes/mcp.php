<?php

declare(strict_types=1);

use Capell\Mcp\Http\Middleware\AuthenticateCapellMcpToken;
use Capell\Mcp\Servers\CapellKnowledgeServer;
use Capell\Mcp\Servers\CapellSiteServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

$homeRoute = config('capell-mcp.routes.home', '/');

if (is_string($homeRoute) && $homeRoute !== '') {
    Route::get($homeRoute, static fn (): array => [
        'name' => 'Capell MCP',
        'status' => 'ok',
        'servers' => [
            'knowledge' => url(config('capell-mcp.routes.knowledge', 'mcp/capell/knowledge')),
            'site' => url(config('capell-mcp.routes.site', 'mcp/capell/site')),
        ],
    ])->name('capell-mcp.home');
}

Mcp::web(config('capell-mcp.routes.knowledge', 'mcp/capell/knowledge'), CapellKnowledgeServer::class);

Mcp::web(config('capell-mcp.routes.site', 'mcp/capell/site'), CapellSiteServer::class)
    ->middleware(AuthenticateCapellMcpToken::class);
