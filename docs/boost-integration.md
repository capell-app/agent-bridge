# Laravel Boost Integration

Capell Agent Bridge has two Agent Bridge surfaces:

- Laravel Boost local MCP server: `php artisan boost:mcp`
- Capell Site Agent Bridge: the authenticated `agent-bridge/capell` route registered by `capell-app/agent-bridge`

Boost is for local development assistance. It can discover Capell package guidance from installed Composer packages and can list or preview registered Capell Agent Bridge capabilities.

The authenticated Capell Site Agent Bridge server is for site operations. It uses Capell Agent Bridge tokens, capability scopes, previews, confirmations, and audit records.

For external-client compatibility, `tests/Fixtures/mcp-v1-external-client.json` freezes transport identifiers, the three registered tool envelopes, schema digests, the closed draft-only catalogue, and safe result fields. Runtime transport/security tests remain the authority for authentication, execution, confirmation replay, and sanitized retention-bounded audit behaviour. Publishing remains human-only in Capell admin.

## Package Discovery

Laravel Boost discovers third-party package guidance from installed packages:

- `vendor/capell-app/*/resources/boost/guidelines`
- `vendor/capell-app/*/resources/boost/skills`

Capell packages keep these files intentionally small. They tell the agent what the package is and where to read more, usually `README.md`, `docs/`, and `src/`.

## Installing In A Host App

Install Laravel Boost and Capell Agent Bridge in the Laravel app:

```bash
composer require --dev laravel/boost
composer require capell-app/agent-bridge:*
php artisan package:discover
php artisan capell:extension-install capell-app/agent-bridge
```

When using zsh, quote the wildcard package constraint:

```bash
composer require 'capell-app/agent-bridge:*'
```

Run Boost installation for the agent you use:

```bash
php artisan boost:install
```

This configures your MCP client to start Boost with `php artisan boost:mcp`. The configuration location depends on the selected client.

## How Capell Agent Bridge Appears In Boost

`Capell\AgentBridge\Providers\AgentBridgeServiceProvider` appends Capell bridge tools to Boost's tool list when Capell Agent Bridge is enabled and `Laravel\Boost\Mcp\Boost` is installed.

```php
config('boost.mcp.tools.include')
```

The bridge tools are:

- `capell-list-capabilities`
- `capell-preview-capability`

These tools read from `Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry`, so package capabilities registered through `CapellAgentBridgeCapabilityProvider` become visible to Boost without each package adding its own Boost-specific tool.

## Capability Flow

For local development:

1. Agent connects to `php artisan boost:mcp`.
2. Agent calls `capell-list-capabilities`.
3. Agent calls `capell-preview-capability` for a safe preview.
4. Mutating confirmation is handled outside Boost through the authenticated Capell Site Agent Bridge server.

For authenticated site operations:

1. Create a Capell Agent Bridge token with the required scopes.
2. Connect an Agent Bridge client to the configured `agent-bridge/capell` route.
3. Call the site capability preview tool.
4. Review the preview and confirmation token.
5. For a high-risk change (`approvalRequired` is true), the person the token acts as approves the preview under **Confirmations** on their user page in the admin.
6. Confirm through the site Agent Bridge confirmation tool with the same payload.
7. Review audit entries if needed.

## Verifying In A Host App

From a Capell host app with Laravel Boost installed, install the Composer package and activate the Agent Bridge extension before checking the tool list. Skip `capell:extension-install` if Agent Bridge is already active.

```bash
composer require 'capell-app/agent-bridge:*' --with-all-dependencies
php artisan package:discover --ansi
php artisan capell:extension-install capell-app/agent-bridge
find vendor/capell-app/agent-bridge/resources/boost -maxdepth 4 -type f -print | sort
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | php artisan boost:mcp
```

Expected:

- `vendor/capell-app/agent-bridge/resources/boost/guidelines/core.blade.php`
- `vendor/capell-app/agent-bridge/resources/boost/skills/capell-agent-bridge-development/SKILL.md`
- The `tools/list` response includes `capell-list-capabilities` and `capell-preview-capability`.

## Common Problems

- `capell-app/agent-bridge` is not in the host app `composer.json`, so no Capell Agent Bridge provider or Boost resources are installed.
- Run `php artisan boost:install` if your MCP client is missing the entry that starts `boost:mcp`.
- Config is cached after changing installed packages. Run `php artisan optimize:clear`.
- A package adds an Agent Bridge capability but does not tag a `CapellAgentBridgeCapabilityProvider`, so the registry never sees it.
- A mutating operation is attempted through Boost instead of the authenticated Capell Site Agent Bridge server.
