<?php

declare(strict_types=1);

// laravel/mcp registers GET and DELETE beside each MCP server only to answer 405; they change nothing.
it('answers the MCP stream methods with 405 and changes nothing', function (string $method): void {
    $this->call($method, '/' . config()->string('capell-agent-bridge.routes.site'))
        ->assertStatus(405)
        ->assertHeader('Allow', 'POST');
})->with(['GET', 'DELETE']);
