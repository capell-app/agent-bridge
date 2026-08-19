<?php

declare(strict_types=1);

it('documents Laravel Boost and Capell Agent Bridge setup paths', function (): void {
    $documentation = file_get_contents(__DIR__ . '/../../docs/boost-integration.md');

    expect($documentation)
        ->toContain('php artisan boost:agent-bridge')
        ->toContain('composer require capell-app/agent-bridge:*')
        ->toContain('vendor/capell-app/*/resources/boost/guidelines')
        ->toContain('vendor/capell-app/*/resources/boost/skills')
        ->toContain('boost.agent-bridge.tools.include')
        ->toContain('capell-list-capabilities')
        ->toContain('capell-preview-capability')
        ->toContain('mcp-v1-external-client.json')
        ->toContain('Publishing remains human-only')
        ->toContain('## Verifying In A Host App')
        // Any machine-local absolute path, not just one author's home
        // directory: generated Boost documentation must stay portable.
        ->not->toMatch('#/(?:Users|home|root|var|tmp|private|opt|mnt|srv)(?:/[A-Za-z0-9._@+\-]+)+#');
});
