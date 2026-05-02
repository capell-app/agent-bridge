# Capell MCP

Capell MCP provides two MCP servers for Capell CMS:

- **Capell Knowledge MCP** exposes public, read-only Capell documentation, package catalog data, conventions, and package recommendations.
- **Capell Site MCP** exposes an authenticated, scoped, auditable MCP surface inside an installed Capell site.

The site server is designed around registered capabilities. Packages opt in by registering capability providers; MCP clients can only see and run capabilities that are installed, enabled, scoped to their token, and allowed by the mapped Capell user.

## Installation

```bash
composer require capell-app/mcp
php artisan vendor:publish --tag=capell-mcp-config
php artisan migrate
```

Register the web endpoints from the package service provider:

- `/`
- `/mcp/capell/knowledge`
- `/mcp/capell/site`

The root route returns a small JSON discovery response for MCP-only hosts such as `mcp.capell.app`. Set `capell-mcp.routes.home` to `null` in a host application if you do not want the package to register `/`.

The site endpoint requires a bearer token created by your application and stored hashed in `capell_mcp_tokens`.

## Filament Admin Prompt Builder

When Capell Admin and Filament are installed, the package registers a small MCP tool item in the admin toolbar. It opens a prompt builder page that asks for the goal, target area, intended action, safety level, constraints, and success criteria, then prepares a structured prompt for an MCP client.

The prompt builder is intentionally non-mutating. It helps admins prepare safer requests while all real changes still go through scoped MCP capabilities, preview tokens, confirmation, policies, and audit logging.

## Capability Workflow

Mutating tools use a two-step workflow:

1. `capell-site-run-capability` returns a preview and confirmation token.
2. `capell-site-confirm-capability` applies the same payload if the token, user, client, scope, and payload hash still match.

This gives MCP clients full admin workflows without turning MCP into a raw remote-code/admin-action executor.
