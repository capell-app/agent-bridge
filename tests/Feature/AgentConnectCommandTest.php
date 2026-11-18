<?php

declare(strict_types=1);

use Capell\AgentBridge\Console\Commands\AgentConnectCommand;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Tests\Fixtures\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    config()->set('capell-agent-bridge.audit_hash_secret', 'audit-secret');
    config()->set('auth.providers.users.model', User::class);
    config()->set('app.url', 'https://site.test');
});

function agentConnectUser(): User
{
    return User::query()->create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => 'secret']);
}

function agentConnectPlainToken(string $output): string
{
    preg_match('/cagent-bridge_[A-Za-z0-9]{48}/', $output, $matches);

    return $matches[0] ?? '';
}

it('creates one draft-only key and prints the Claude Code command once', function (): void {
    $user = agentConnectUser();

    $exit = Artisan::call('capell:agent:connect', ['--user' => 'editor@example.com', '--no-interaction' => true]);
    $output = Artisan::output();
    $plain = agentConnectPlainToken($output);
    $token = CapellAgentBridgeToken::query()->sole();

    expect($exit)->toBe(0)
        ->and($plain)->not->toBe('')
        ->and(substr_count($output, $plain))->toBe(1)
        ->and($output)->toContain(sprintf('claude mcp add --transport http capell https://site.test/agent-bridge/capell --header "Authorization: Bearer %s"', $plain))
        ->and($output)->toContain('shown once')
        ->and($token->scopes)->toBe(AgentConnectCommand::DEFAULT_SCOPES)
        ->and($token->scopes)->not->toContain('capell.pages.publish')
        ->and($token->user?->is($user))->toBeTrue()
        ->and(CapellAgentBridgeToken::findForPlainTextToken($plain)?->is($token))->toBeTrue()
        ->and($token->token_hash)->not->toBe($plain);
});

it('prints the exact Cursor configuration', function (): void {
    agentConnectUser();

    Artisan::call('capell:agent:connect', ['--user' => 'editor@example.com', '--client' => 'cursor', '--no-interaction' => true]);
    $output = Artisan::output();
    $plain = agentConnectPlainToken($output);

    expect($output)->toContain(json_encode([
        'mcpServers' => ['capell' => [
            'url' => 'https://site.test/agent-bridge/capell',
            'headers' => ['Authorization' => 'Bearer ' . $plain],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
});

it('prints the local stdio variant for Claude Code', function (): void {
    agentConnectUser();

    Artisan::call('capell:agent:connect', ['--user' => 'editor@example.com', '--local' => true, '--no-interaction' => true]);
    $output = Artisan::output();
    $plain = agentConnectPlainToken($output);

    expect($output)->toContain(sprintf('claude mcp add capell --env CAPELL_AGENT_BRIDGE_LOCAL_TOKEN=%s -- php artisan mcp:start capell-site', $plain))
        ->and($output)->not->toContain('https://site.test');
});

it('requires --user when not interactive', function (): void {
    agentConnectUser();

    $exit = Artisan::call('capell:agent:connect', ['--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Pass --user')
        ->and(CapellAgentBridgeToken::query()->count())->toBe(0);
});

it('refuses an unknown user or client without creating a key', function (array $options, string $message): void {
    agentConnectUser();

    $exit = Artisan::call('capell:agent:connect', [...$options, '--no-interaction' => true]);

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain($message)
        ->and(CapellAgentBridgeToken::query()->count())->toBe(0);
})->with([
    'unknown user' => [['--user' => 'nobody@example.com'], 'No user matches'],
    'unknown client' => [['--user' => 'editor@example.com', '--client' => 'vim'], 'Unknown client'],
]);

it('refuses to send a key over plain http in production', function (): void {
    agentConnectUser();
    app()->detectEnvironment(fn (): string => 'production');

    $exit = Artisan::call('capell:agent:connect', ['--user' => 'editor@example.com', '--url' => 'http://site.test', '--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('must use https')
        ->and(CapellAgentBridgeToken::query()->count())->toBe(0);
});

it('refuses until the connectivity check passes', function (): void {
    agentConnectUser();
    config()->set('capell-agent-bridge.rate_limit_enabled', false);

    $exit = Artisan::call('capell:agent:connect', ['--user' => 'editor@example.com', '--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('rate limits are off')
        ->and(CapellAgentBridgeToken::query()->count())->toBe(0);
});
