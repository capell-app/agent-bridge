<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Models\CapellAgentBridgeAuditEntry;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The audit entries that belong to a staff member: those recorded against them, and
 * those written by a token they own, which may carry no user of its own.
 */
final class CapellAgentBridgeAuditEntryQuery
{
    /**
     * @return Builder<CapellAgentBridgeAuditEntry>
     */
    public static function forSubject(Model $subject): Builder
    {
        $tokenIds = CapellAgentBridgeToken::query()
            ->where('user_type', $subject->getMorphClass())
            ->where('user_id', $subject->getKey())
            ->select('id');

        return CapellAgentBridgeAuditEntry::query()->where(static function (Builder $query) use ($subject, $tokenIds): void {
            $query
                ->where(static function (Builder $own) use ($subject): void {
                    $own->where('user_type', $subject->getMorphClass())->where('user_id', $subject->getKey());
                })
                ->orWhereIn('agent_bridge_token_id', $tokenIds);
        });
    }
}
