<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Extenders;

use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Support\Bridges\AbstractUserResourceBridge;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeAuditEntriesRelationManager;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeConfirmationsRelationManager;
use Capell\AgentBridge\Filament\Resources\Users\RelationManagers\AgentBridgeTokensRelationManager;
use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Capell\AgentBridge\Settings\AgentBridgeSettings;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Override;
use Throwable;

final class AgentBridgeUserSchemaExtender extends AbstractUserResourceBridge
{
    #[Override]
    public function supports(UserSchemaContextData $context): bool
    {
        try {
            return resolve(AgentBridgeSettings::class)->enable_user_resource_bridge;
        } catch (Throwable) {
            return false;
        }
    }

    #[Override]
    public function extendSidebarComponents(Schema $schema, UserSchemaContextData $context): array
    {
        if (! $context->record instanceof Model) {
            return [];
        }

        return [
            Section::make(__('capell-agent-bridge::admin.user_agent_activity'))
                ->compact()
                ->schema([
                    TextEntry::make('agent_bridge_activity_summary')
                        ->hiddenLabel()
                        ->state(fn (): string => $this->summaryLine($context->record)),
                ]),
        ];
    }

    #[Override]
    public function extendRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array
    {
        return [
            ...$relationManagers,
            AgentBridgeTokensRelationManager::class,
            AgentBridgeConfirmationsRelationManager::class,
            AgentBridgeAuditEntriesRelationManager::class,
        ];
    }

    /**
     * @return array{
     *     tokens: int,
     *     confirmations: int,
     *     approved: int,
     *     audit_entries: int,
     *     active: int,
     *     expired: int,
     *     last_used: string,
     *     next_expiry: string
     * }
     */
    public function summarizeUserActivity(Model $user): array
    {
        $tokens = AgentBridgeTokensRelationManager::scopedQueryForUser(CapellAgentBridgeToken::query(), $user)->get();
        $confirmations = AgentBridgeConfirmationsRelationManager::scopedQueryForUser(CapellAgentBridgeConfirmation::query(), $user)->get();
        $auditEntries = AgentBridgeAuditEntriesRelationManager::scopedQueryForUser(CapellAgentBridgeAuditEntry::query(), $user)->get();
        $now = Date::now();
        $lastUsedAt = $tokens
            ->pluck('last_used_at')
            ->filter()
            ->max();
        $nextExpiryAt = $tokens
            ->pluck('expires_at')
            ->filter(fn (mixed $expiresAt): bool => $this->parseDate($expiresAt)?->greaterThanOrEqualTo($now) === true)
            ->min();

        return [
            'tokens' => $tokens->count(),
            'confirmations' => $confirmations->count(),
            'approved' => $confirmations->whereNotNull('used_at')->count(),
            'audit_entries' => $auditEntries->count(),
            'active' => $tokens->reject(fn (CapellAgentBridgeToken $token): bool => $token->isExpired())->count(),
            'expired' => $tokens->filter(fn (CapellAgentBridgeToken $token): bool => $token->isExpired())->count(),
            'last_used' => $this->formatDate($lastUsedAt),
            'next_expiry' => $this->formatDate($nextExpiryAt),
        ];
    }

    private function summaryLine(Model $user): string
    {
        return __('capell-agent-bridge::admin.user_agent_activity_summary', $this->summarizeUserActivity($user));
    }

    private function formatDate(mixed $date): string
    {
        if ($date === null || $date === '') {
            return '-';
        }

        return $this->parseDate($date)?->diffForHumans() ?? '-';
    }

    private function parseDate(mixed $date): ?CarbonInterface
    {
        if ($date instanceof DateTimeInterface || is_int($date) || is_float($date) || is_string($date) && $date !== '') {
            return Date::parse($date);
        }

        return null;
    }
}
