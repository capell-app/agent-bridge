<?php

declare(strict_types=1);

return [
    'routes' => [
        // Set any route to null to stop this package registering it in the host app.
        'home' => null,
        'knowledge' => null,
        'site' => 'agent-bridge/capell',
    ],

    'site_auth_guard' => env('CAPELL_AGENT_BRIDGE_AUTH_GUARD', 'web'),

    'local' => [
        'handle' => env('CAPELL_AGENT_BRIDGE_LOCAL_HANDLE', 'capell-site'),
        'token' => env('CAPELL_AGENT_BRIDGE_LOCAL_TOKEN', ''),
    ],

    'token_prefix' => env('CAPELL_AGENT_BRIDGE_TOKEN_PREFIX', 'cagent-bridge_'),

    'confirmation_ttl_minutes' => env('CAPELL_AGENT_BRIDGE_CONFIRMATION_TTL_MINUTES', 10),

    'rate_limit_per_minute' => env('CAPELL_AGENT_BRIDGE_RATE_LIMIT_PER_MINUTE', 60),

    'rate_limit_per_ip_per_minute' => env('CAPELL_AGENT_BRIDGE_RATE_LIMIT_PER_IP_PER_MINUTE', 120),

    'rate_limit_enabled' => env('CAPELL_AGENT_BRIDGE_RATE_LIMIT_ENABLED', true),

    'last_used_throttle_minutes' => env('CAPELL_AGENT_BRIDGE_LAST_USED_THROTTLE_MINUTES', 5),

    'accept_legacy_token_hashes' => env('CAPELL_AGENT_BRIDGE_ACCEPT_LEGACY_TOKEN_HASHES', false),

    'inspect_app_runtime' => env('CAPELL_AGENT_BRIDGE_INSPECT_APP_RUNTIME', false),

    'audit_retention_days' => env('CAPELL_AGENT_BRIDGE_AUDIT_RETENTION_DAYS', 90),

    'audit_hash_secret' => env('CAPELL_AGENT_BRIDGE_AUDIT_HASH_SECRET'),

    'enable_user_resource_bridge' => true,

    /*
     * Deterministic command interpretation thresholds. These calibrate how much
     * evidence a fallback ranking needs before it may be offered as a
     * suggestion. They never change scopes, policies, or execution authority: a
     * suggestion is still only a proposed capability input.
     */
    'interpretation' => [
        // Below this score the leading capability is treated as noise and the outcome is unmatched.
        'minimum_evidence_score' => env('CAPELL_AGENT_BRIDGE_MINIMUM_EVIDENCE_SCORE', 12),

        // Fraction of the command's meaningful terms the leader must account for.
        // Guards against a single common word scoring highly across many fields.
        'minimum_term_coverage' => env('CAPELL_AGENT_BRIDGE_MINIMUM_TERM_COVERAGE', 0.5),

        // How far ahead of the runner-up the leader must be to count as a clear suggestion.
        'suggestion_separation_ratio' => env('CAPELL_AGENT_BRIDGE_SUGGESTION_SEPARATION_RATIO', 1.35),

        // How many ranked capabilities to return alongside a suggested or ambiguous outcome.
        'suggestion_limit' => env('CAPELL_AGENT_BRIDGE_SUGGESTION_LIMIT', 5),
    ],

    'public_docs_paths' => [
        base_path('README.md'),
        base_path('docs'),
        base_path('packages/*/README.md'),
        base_path('packages/*/docs'),
    ],
];
