<?php

declare(strict_types=1);

use Capell\AgentBridge\Actions\CreateAgentBridgeTokenAction;
use Capell\AgentBridge\Http\Middleware\RequireAgentBridgeScope;
use Capell\AgentBridge\Tests\Fixtures\User;

/**
 * @param  list<string>  $scopes
 * @return array<string, string>
 */
function knowledgeServerHeaders(array $scopes): array
{
    $user = User::query()->create([
        'name' => 'Knowledge client',
        'email' => 'knowledge-' . implode('-', $scopes) . '@example.com',
        'password' => 'secret',
    ]);

    config()->set('capell-agent-bridge.routes.knowledge', 'agent-bridge/capell/knowledge');
    config()->set('capell-agent-bridge.routes.site');
    config()->set('capell-agent-bridge.routes.home');

    require __DIR__ . '/../../routes/agent-bridge.php';

    return [
        'Authorization' => 'Bearer ' . CreateAgentBridgeTokenAction::run($user, 'Knowledge client', $scopes)['plainTextToken'],
        'Accept' => 'application/json, text/event-stream',
    ];
}

/** @return array<string, mixed> */
function knowledgeInitializeRequest(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'knowledge-scope-test', 'version' => '1.0.0'],
        ],
    ];
}

it('refuses a token without the knowledge scope on the knowledge server', function (): void {
    $this->withHeaders(knowledgeServerHeaders(['capell.pages.read']))
        ->postJson('/agent-bridge/capell/knowledge', knowledgeInitializeRequest())
        ->assertForbidden();
});

it('serves the knowledge server to a token holding the knowledge scope', function (): void {
    $this->withHeaders(knowledgeServerHeaders([RequireAgentBridgeScope::KNOWLEDGE_READ]))
        ->postJson('/agent-bridge/capell/knowledge', knowledgeInitializeRequest())
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Capell Knowledge Agent Bridge');
});

it('serves the knowledge server to a token holding every scope', function (): void {
    $this->withHeaders(knowledgeServerHeaders(['*']))
        ->postJson('/agent-bridge/capell/knowledge', knowledgeInitializeRequest())
        ->assertOk();
});
