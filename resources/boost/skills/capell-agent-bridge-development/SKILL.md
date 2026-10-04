---
name: capell-agent-bridge-development
description: Agent Bridge servers and capability adapters with token auth, previews, confirmations, and audit records. Use when editing Capell Agent Bridge servers, tokens, capabilities, previews, or Boost bridge tools.
---

# Capell Agent Bridge

Agent Bridge servers and capability adapters with token auth, previews, confirmations, and audit records.

## Look

- `packages/agent-bridge/src`
- `packages/agent-bridge/docs/boost-integration.md`
- `packages/agent-bridge/tests/Fixtures/mcp-v1-external-client.json`
- `packages/agent-bridge/README.md`

## Rules

- Register package operations through capability providers.
- Mutating site operations need preview, confirmation, scopes, and audit.
- Boost tools stay local-development bridges, not privileged bypasses.
- Preserve the frozen three-tool MCP v1 catalogue fixture for both HTTP and local stdio; runtime security tests remain authoritative for execution behaviour.
- HTTP tokens and local principals remain per-user; scope never replaces current ownership and policy checks.
- Confirmation is single-use. Only media upload has a UUID idempotency key; do not promise generic mutation replay.
- Refuse publishing, visibility changes, package/cache operations, AI Creator apply/build/export, and arbitrary registry contributions.
- Run `vendor/bin/pest packages/agent-bridge/tests`.
