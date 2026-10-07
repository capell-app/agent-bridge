# Capabilities

Agent Bridge exposes small, auditable capabilities to the Laravel Boost server and the authenticated Capell Site Agent Bridge server. Capabilities must be registered through `CapellAgentBridgeCapabilityRegistry`; do not expose arbitrary services or controllers as tools.

## Frozen external-client MCP v1

The external-client catalogue contract is frozen in `tests/Fixtures/mcp-v1-external-client.json`. It snapshots transport identifiers, the three registered tool envelopes, the closed draft-only capability projection, schema digests, and safe result fields; runtime transport/security tests remain the authority for authentication and execution behaviour. Remote streamable HTTP uses `agent-bridge/capell` with a revocable, expiring per-user bearer token. Local Artisan stdio uses the `capell-site` handle and requires an explicitly selected local user token.

Token scope is necessary but is not authority by itself: current user ownership, token lifecycle, record access, and policy are rechecked. Read calls are audited without confirmation. Draft mutations are preview-first and require a short-lived, single-use confirmation with the exact payload; replay is refused rather than silently rerun. Media upload additionally requires its UUID idempotency key, so a retry with the same content resolves to the claimed upload while key reuse with different content conflicts.

MCP v1 uses a closed allowlist: every capability outside it is refused. This includes publishing and visibility transitions, package installation, cache operations, AI Creator build/apply/export, arbitrary registry contributions, shell/Composer execution, and editor or signed URL access. The broader admin capability catalogue does not expand this external-client allowlist. Audit records are sanitized and retention-bounded rather than immutable.

Run the real transport catalogue/refusal parity check against a prepared host with a least-privileged Agent Bridge token:

```bash
CAPELL_AGENT_BRIDGE_VERIFY_TOKEN='...' \
CAPELL_AGENT_BRIDGE_VERIFY_HTTP_URL='https://example.test/agent-bridge/capell' \
CAPELL_AGENT_BRIDGE_VERIFY_ARTISAN='/path/to/host/artisan' \
php vendor/capell-app/agent-bridge/scripts/verify-mcp-v1-transports.php
```

The host must use a persistent database so the spawned Artisan stdio process can resolve the same token as HTTP. The verifier initializes both transports, compares tools and capabilities with the frozen fixture, and proves publication refusal. Draft creation/edit, media upload, cross-site policy, confirmation replay, and audit inspection still require seeded host records and remain separate release-gate scenarios.

The read-only knowledge server also exposes `capell://agent-bridge/capabilities`, a capability catalog for operators and reviewers. It lists each registered capability with its scope, server visibility, risk, preview support, confirmation requirement, required package, and policy ability. Use it as the governance inventory when reviewing token scopes or deciding whether a capability should be available to an agent client.

## Register a Capability Provider

Packages can register a provider by tagging it with `CapellAgentBridgeCapabilityProvider::class`.

```php
use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityProvider;
use Capell\AgentBridge\Data\CapabilityData;
use Capell\AgentBridge\Enums\CapabilityRiskEnum;
use Capell\AgentBridge\Enums\CapabilityServerEnum;
use Capell\AgentBridge\Support\CapellAgentBridgeCapabilityRegistry;

final class DemoCapabilityProvider implements CapellAgentBridgeCapabilityProvider
{
    public function registerCapabilities(CapellAgentBridgeCapabilityRegistry $registry): void
    {
        $registry->register(new CapabilityData(
            key: 'demo.clear-cache',
            name: 'Clear demo cache',
            description: 'Preview or clear the demo package cache.',
            scope: 'demo.cache.write',
            server: CapabilityServerEnum::Site,
            risk: CapabilityRiskEnum::Medium,
            actionClass: ClearDemoCacheCapabilityAction::class,
            requiredPackage: 'capell-app/demo-kit',
            supportsPreview: true,
            requiresConfirmation: true,
            auditEvent: 'demo.cache.clear',
        ));
    }
}

$this->app->singleton(DemoCapabilityProvider::class);
$this->app->tag(DemoCapabilityProvider::class, CapellAgentBridgeCapabilityProvider::class);
```

Keys and scopes are part of the external contract. Keep them stable once a client has been issued scopes.

## Write a Capability Action

Actions implement both `preview()` and `execute()`. Mutating actions should return enough preview data for a human or calling agent to see what will change before confirming.

```php
use Capell\AgentBridge\Contracts\CapellAgentBridgeCapabilityAction;
use Capell\AgentBridge\Data\CapabilityInvocationData;
use Capell\AgentBridge\Data\CapabilityResultData;

final class ClearDemoCacheCapabilityAction implements CapellAgentBridgeCapabilityAction
{
    public function preview(CapabilityInvocationData $invocation): CapabilityResultData
    {
        return new CapabilityResultData(
            ok: true,
            message: 'Demo cache would be cleared.',
            data: [
                'cache_key' => 'capell.demo-kit',
            ],
        );
    }

    public function execute(CapabilityInvocationData $invocation): CapabilityResultData
    {
        cache()->forget('capell.demo-kit');

        return new CapabilityResultData(
            ok: true,
            message: 'Demo cache cleared.',
        );
    }
}
```

Keep side effects in `execute()`. `preview()` should not write.

## Risk And Confirmation

`CapabilityData::needsConfirmation()` returns true when either `requiresConfirmation` is true or the risk enum requires it. Use lower risk only for read-only actions.

| Field             | Meaning                                                                             |
| ----------------- | ----------------------------------------------------------------------------------- |
| `scope`           | Token scope required to see and invoke the capability.                              |
| `server`          | Server visibility, such as Boost or authenticated Site bridge.                      |
| `risk`            | Risk level used by confirmation rules.                                              |
| `requiredPackage` | Optional package name checked through Capell Core before the capability is visible. |
| `policyAbility`   | Optional authorization ability.                                                     |
| `auditEvent`      | Event name stored by the audit action.                                              |
| `commandPatterns` | Optional direct-command grammar owned by this capability. See below.                |

## Direct Command Patterns

Agent Bridge is a deterministic control plane, not an inference provider. It never calls a model: the connected AI client supplies the reasoning, and Agent Bridge constrains, validates, previews, confirms, and audits what that client proposes.

The in-product command surface interprets a bounded set of direct commands. Each capability owns its own grammar through `commandPatterns`, so an extension package contributes command shapes alongside its capability instead of editing Agent Bridge.

```php
use Capell\AgentBridge\Data\CapabilityCommandPatternData;

commandPatterns: [
    new CapabilityCommandPatternData(
        expression: '/^send\s+(?:the\s+)?(?<newsletter>.+?)\s+newsletter\s+(?:at|on)\s+(?<send_at>.+)$/i',
        phrase: (string) __('demo-kit::admin.command_pattern_send_newsletter'),
        fields: ['newsletter' => ['newsletter'], 'send_at' => ['send_at']],
        required: ['newsletter', 'send_at'],
    ),
],
```

| Field        | Meaning                                                                                    |
| ------------ | ------------------------------------------------------------------------------------------ |
| `expression` | Anchored PCRE pattern matched against the squished command. Never exposed to clients.      |
| `phrase`     | Translated, human-readable shape shown to administrators and trusted clients.              |
| `fields`     | Named capture => the payload fields it populates. One capture may populate several fields. |
| `required`   | Payload fields this pattern guarantees to supply.                                          |

Registration fails with the capability key and offending field when a pattern emits a field the capability's input schema (or input Data boundary) does not declare, claims a `required` field no capture supplies, or uses an unanchored or invalid expression.

### Outcomes

Interpretation returns one of four explicit outcomes, and never manufactures a capability key or payload:

| Outcome     | Meaning                                                                                            |
| ----------- | -------------------------------------------------------------------------------------------------- |
| `exact`     | One pattern produced a complete, schema-compatible payload. Confidence is 1.0.                     |
| `suggested` | No pattern matched, but one visible capability ranked clearly above the rest. Nothing is selected. |
| `ambiguous` | Several capabilities are plausible, or a pattern could not extract a value it guarantees.          |
| `unmatched` | No visible capability has meaningful evidence of a match.                                          |

Only capabilities visible to the current server and token scopes take part in interpretation or ranking. Interpretation does not run anything: preview, policy recheck, and one-use confirmation remain the only invocation path, and an exact match is still only a proposed capability input.

## Config Keys

| Key                                               | Use                                                                        |
| ------------------------------------------------- | -------------------------------------------------------------------------- |
| `capell-agent-bridge.routes.*`                    | Route registration switches. Set a route to `null` to stop registering it. |
| `capell-agent-bridge.confirmation_ttl_minutes`    | Lifetime of confirmation tokens for mutating capabilities.                 |
| `capell-agent-bridge.audit_retention_days`        | Audit retention window used by the pruning command. Defaults to 90 days.   |
| `capell-agent-bridge.public_docs_paths`           | Documentation paths exposed to knowledge tools.                            |
| `capell-agent-bridge.enable_user_resource_bridge` | Adds token and bridge controls to the user admin resource.                 |
| `capell-agent-bridge.routes.home`                 | Optional discovery document route. Disabled by default.                    |
| `capell-agent-bridge.routes.knowledge`            | Optional knowledge server route. Disabled by default.                      |
| `capell-agent-bridge.routes.site`                 | Authenticated site MCP server route. Enabled by default.                   |
| `capell-agent-bridge.interpretation.*`            | Ranking thresholds for direct-command interpretation. See below.           |

Interpretation thresholds are conservative by default. Changing them never changes scopes, policies, or execution authority.

| Key                                                              | Use                                                                                                              |
| ---------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `capell-agent-bridge.interpretation.minimum_evidence_score`      | Below this ranking score the outcome is `unmatched`. Defaults to `12`.                                           |
| `capell-agent-bridge.interpretation.minimum_term_coverage`       | Fraction of the command's meaningful terms the leader must account for. Defaults to `0.5`.                       |
| `capell-agent-bridge.interpretation.suggestion_separation_ratio` | How far ahead of the runner-up the leader must be to be `suggested` rather than `ambiguous`. Defaults to `1.35`. |
| `capell-agent-bridge.interpretation.suggestion_limit`            | How many ranked capabilities accompany a suggested or ambiguous outcome. Defaults to `5`.                        |

Token, confirmation, and audit table names are migration concerns. Keep them out of public setup docs unless a host app overrides storage.
