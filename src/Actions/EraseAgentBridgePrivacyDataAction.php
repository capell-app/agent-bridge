<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Actions;

use Capell\AgentBridge\Models\CapellAgentBridgeConfirmation;
use Capell\AgentBridge\Models\CapellAgentBridgeToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Removes the personal data Agent Bridge holds about a staff member. Their tokens
 * stop working and lose the address they were created from, their pending
 * confirmations are deleted, and the audit entries their tokens wrote keep the event
 * but lose the browser and address fingerprint, so the trail of what changed stays
 * intact without identifying the person's device.
 *
 * Safe to run again: a second run finds nothing left to change.
 *
 * @method static int run(Model $subject)
 */
final class EraseAgentBridgePrivacyDataAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $subject): int
    {
        return DB::transaction(static function () use ($subject): int {
            $tokens = CapellAgentBridgeToken::query()
                ->where('user_type', $subject->getMorphClass())
                ->where('user_id', $subject->getKey());

            $tokenIds = (clone $tokens)->pluck('id');

            $affected = self::count(CapellAgentBridgeConfirmation::query()->whereIn('agent_bridge_token_id', $tokenIds)->delete());

            $affected += self::count(CapellAgentBridgeAuditEntryQuery::forSubject($subject)
                ->where(static function ($query): void {
                    $query->whereNotNull('ip_address_hash')->orWhereNotNull('user_agent');
                })
                ->update(['ip_address_hash' => null, 'user_agent' => null]));

            $affected += self::count((clone $tokens)
                ->where(static function ($query): void {
                    $query->whereNotNull('created_from_ip')->orWhere('is_enabled', true);
                })
                ->update([
                    'created_from_ip' => null,
                    'is_enabled' => false,
                    'revoked_at' => now(),
                ]));

            return $affected;
        });
    }

    private static function count(mixed $result): int
    {
        return is_int($result) ? $result : 0;
    }
}
