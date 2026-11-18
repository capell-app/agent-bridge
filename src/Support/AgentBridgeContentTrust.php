<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

/**
 * Labels which parts of a bridge response hold text written by people rather than by
 * Capell. Page titles, bodies, names and uploaded file names can carry instructions aimed
 * at the agent, so clients are told to treat those fields as data.
 */
final class AgentBridgeContentTrust
{
    /**
     * @return array{untrustedFields: list<string>, notice: string}
     */
    public static function forCapabilityResult(string $resultKey): array
    {
        return [
            // Messages and warnings can repeat a page or site name the request named.
            'untrustedFields' => [$resultKey . '.data', $resultKey . '.message', $resultKey . '.warnings'],
            'notice' => self::notice('capability'),
        ];
    }

    /**
     * @return array{untrustedFields: list<string>, notice: string}
     */
    public static function forAuditEntries(): array
    {
        return [
            'untrustedFields' => ['entries.*.payload', 'entries.*.result'],
            'notice' => self::notice('capability'),
        ];
    }

    /**
     * @return array{untrustedFields: list<string>, notice: string}
     */
    public static function forKnowledgeDocument(): array
    {
        return [
            'untrustedFields' => ['content'],
            'notice' => self::notice('knowledge'),
        ];
    }

    private static function notice(string $kind): string
    {
        return AgentBridgeValue::string(
            __('capell-agent-bridge::messages.content_trust.' . $kind),
            'Agent Bridge content trust notice',
        );
    }
}
