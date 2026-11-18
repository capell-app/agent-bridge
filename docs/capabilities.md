# Capabilities

Agent Bridge exposes small, auditable capabilities to the Laravel Boost server and the authenticated Capell Site Agent Bridge server. Capabilities must be registered through `CapellAgentBridgeCapabilityRegistry`; do not expose arbitrary services or controllers as tools.

## Frozen external-client MCP v1

The external-client catalogue contract is frozen in `tests/Fixtures/mcp-v1-external-client.json`. It snapshots transport identifiers, the three registered tool envelopes, the closed draft-only capability projection, schema digests, and safe result fields; runtime transport/security tests remain the authority for authentication and execution behaviour. Remote streamable HTTP uses `agent-bridge/capell` with a revocable, expiring per-user bearer token. Local Artisan stdio uses the `capell-site` handle and requires an explicitly selected local user token.

Token scope is necessary but is not authority by itself: current user ownership, token lifecycle, record access, and policy are rechecked. A token can also be limited to chosen capabilities and sites: a capability outside its list is refused and left out of listings and suggestions, and a site limit narrows every site and page lookup made through the bridge's record access while the capability runs. A token runs only the capabilities in the MCP v1 contract, and every one of them resolves its site or page through that record access, so the site limit covers all of them. Built-in capabilities outside the contract, such as clearing the cache or searching packages, cannot be run with a token. A test fails if a capability joins the contract without proving it keeps to a token's sites. Read calls are audited without confirmation. Draft mutations are preview-first and require a short-lived, single-use confirmation with the exact payload; replay is refused rather than silently rerun. Because they are high risk, the bearer token cannot confirm them alone: the person the token acts as must first approve the preview in the admin, and until then confirming returns `approval_required` with nothing changed. The preview response says so in `approvalRequired` and `nextStep`. A confirmed page edit or new draft page records a revert point in its audit entry: the page and its versions either side of the change, never the content. A person in the admin can then undo it from the entry until the page changes again. Media upload additionally requires its UUID idempotency key, so a retry with the same content resolves to the claimed upload while key reuse with different content conflicts.

MCP v1 uses a closed allowlist: every capability outside it is refused. This includes publishing and visibility transitions, package installation, cache operations, AI Creator build/apply/export, arbitrary registry contributions, shell/Composer execution, and editor or signed URL access. The broader admin capability catalogue does not expand this external-client allowlist. Audit records are sanitized and retention-bounded rather than immutable.

Run the real transport catalogue/refusal parity check against a prepared host with a least-privileged Agent Bridge token:

```bash
CAPELL_AGENT_BRIDGE_VERIFY_TOKEN='...' \
CAPELL_AGENT_BRIDGE_VERIFY_HTTP_URL='https://example.test/agent-bridge/capell' \
CAPELL_AGENT_BRIDGE_VERIFY_ARTISAN='/path/to/host/artisan' \
php vendor/capell-app/agent-bridge/scripts/verify-mcp-v1-transports.php
```

The host must use a persistent database so the spawned Artisan stdio process can resolve the same token as HTTP. The verifier initializes both transports, compares tools and capabilities with the frozen fixture, and proves publication refusal. Draft creation/edit, media upload, cross-site policy, confirmation replay, and audit inspection still require seeded host records and remain separate release-gate scenarios.

The knowledge server serves an allowlist of Markdown. Documents under `agent_knowledge.published_paths` (package READMEs and `docs` folders) are served whole; documents under `agent_knowledge.opt_in_paths` (the application `README.md` and `docs`) are served only when their front matter sets `agent_knowledge: true`. Front matter `agent_knowledge: false` withholds a document from any path, and hidden folders, files over 256 KiB and links leading outside the application are never served, and a link is judged by the file it points to. Paths a host published under the old `public_docs_paths` setting are treated as opt-in paths. A document is returned with its `provenance` (package or application documentation) and a `contentTrust` notice, and package metadata gives repository-relative paths.

Capability results carry the same `contentTrust` block. Its `untrustedFields` names the part of the response that holds site content written by people (the `data`, `message` and `warnings` of a result or preview, which can repeat a name the request gave, and the payloads and results of audit entries), so a client can show or edit that text without following instructions hidden in it.

The read-only knowledge server also exposes `capell://agent-bridge/capabilities`, a capability catalogue for operators and reviewers. It lists each registered capability with its scope, server visibility, risk, preview support, confirmation requirement, required package, and policy ability. Use it as the governance inventory when reviewing token scopes or deciding whether a capability should be available to an agent client.

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

Previews at the risk levels in `capell-agent-bridge.human_approval_risks` (high and destructive by default) also need human approval. `ApproveAgentBridgeConfirmationAction` records it only for the person the confirmation belongs to, outside any Agent Bridge request, while their token is still usable and they pass the capability's `policyAbility`. `ConfirmAgentBridgeCapabilityAction` then refuses with `AgentBridgeConfirmationAwaitingApprovalException` until that approval exists. The requirement is stored on the confirmation when it is previewed, so lowering it later does not release open previews. `DeclineAgentBridgeConfirmationAction` closes a confirmation for good.

| Field             | Meaning                                                                             |
| ----------------- | ----------------------------------------------------------------------------------- |
| `scope`           | Token scope required to see and invoke the capability.                              |
| `server`          | Server visibility, such as Boost or authenticated Site bridge.                      |
| `risk`            | Risk level used by confirmation rules.                                              |
| `requiredPackage` | Optional package name checked through Capell Core before the capability is visible. |
| `policyAbility`   | Optional authorisation ability.                                                     |
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

| Key                                                   | Use                                                                        |
| ----------------------------------------------------- | -------------------------------------------------------------------------- |
| `capell-agent-bridge.routes.*`                        | Route registration switches. Set a route to `null` to stop registering it. |
| `capell-agent-bridge.confirmation_ttl_minutes`        | Lifetime of confirmation tokens for mutating capabilities.                 |
| `capell-agent-bridge.human_approval_risks`            | Risk levels whose previews need the token owner's approval in the admin.   |
| `capell-agent-bridge.approval_ttl_minutes`            | Lifetime of a preview that needs approval. Defaults to 60 minutes.         |
| `capell-agent-bridge.audit_retention_days`            | Audit retention window used by the pruning command. Defaults to 90 days.   |
| `capell-agent-bridge.agent_knowledge.published_paths` | Package documentation served whole to knowledge tools.                     |
| `capell-agent-bridge.agent_knowledge.opt_in_paths`    | Application documentation served only when marked for agents.              |
| `capell-agent-bridge.enable_user_resource_bridge`     | Adds token and bridge controls to the user admin resource.                 |
| `capell-agent-bridge.routes.home`                     | Optional discovery document route. Disabled by default.                    |
| `capell-agent-bridge.routes.knowledge`                | Optional knowledge server route. Disabled by default.                      |
| `capell-agent-bridge.routes.site`                     | Authenticated site MCP server route. Enabled by default.                   |
| `capell-agent-bridge.interpretation.*`                | Ranking thresholds for direct-command interpretation. See below.           |

Interpretation thresholds are conservative by default. Changing them never changes scopes, policies, or execution authority.

| Key                                                              | Use                                                                                                              |
| ---------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `capell-agent-bridge.interpretation.minimum_evidence_score`      | Below this ranking score the outcome is `unmatched`. Defaults to `12`.                                           |
| `capell-agent-bridge.interpretation.minimum_term_coverage`       | Fraction of the command's meaningful terms the leader must account for. Defaults to `0.5`.                       |
| `capell-agent-bridge.interpretation.suggestion_separation_ratio` | How far ahead of the runner-up the leader must be to be `suggested` rather than `ambiguous`. Defaults to `1.35`. |
| `capell-agent-bridge.interpretation.suggestion_limit`            | How many ranked capabilities accompany a suggested or ambiguous outcome. Defaults to `5`.                        |

Token, confirmation, and audit table names are migration concerns. Keep them out of public setup docs unless a host app overrides storage.
