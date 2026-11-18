<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Lists the personal data Agent Bridge holds about a staff member: the tokens they
 * created, with the address each was created from, and the audit entries their
 * tokens wrote, with the browser and address fingerprint of each request. Token
 * secrets and record identifiers are left out.
 *
 * @method static array<string, array<int, array<string, mixed>>> run(Model $subject)
 */
final class BuildAgentBridgePrivacyExportAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function handle(Model $subject): array
    {
        return array_filter([
            'tokens' => CapellAgentBridgeToken::query()
                ->where('user_type', $subject->getMorphClass())
                ->where('user_id', $subject->getKey())
                ->oldest('id')
                ->get()
                ->map(static fn (CapellAgentBridgeToken $token): array => [
                    'name' => $token->name,
                    'scopes' => $token->scopes,
                    'created_from_ip' => $token->created_from_ip,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'revoked_at' => $token->revoked_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'audit_entries' => CapellAgentBridgeAuditEntryQuery::forSubject($subject)
                ->oldest('id')
                ->get()
                ->map(static fn (CapellAgentBridgeAuditEntry $entry): array => [
                    'event' => $entry->event,
                    'capability_key' => $entry->capability_key,
                    'scope' => $entry->scope,
                    'ip_address_hash' => $entry->ip_address_hash,
                    'user_agent' => $entry->user_agent,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ], static fn (array $records): bool => $records !== []);
    }
}
