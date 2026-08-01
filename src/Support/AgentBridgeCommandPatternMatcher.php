<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\AgentBridge\Data\CapabilityCommandPatternData;
use Illuminate\Support\Str;

/**
 * Deterministic extraction of payload values from a direct command.
 *
 * Captured values are trimmed and stripped of surrounding punctuation and a
 * leading article. They are never expanded, corrected, or inferred: whatever the
 * administrator typed is what the capability is asked to resolve.
 */
final class AgentBridgeCommandPatternMatcher
{
    /**
     * @return array{payload: array<string, string>, missingFields: list<string>}|null
     *                                                                                 Null when the pattern does not match the command at all.
     */
    public function match(CapabilityCommandPatternData $pattern, string $command): ?array
    {
        if (preg_match($pattern->expression, $command, $matches) !== 1) {
            return null;
        }

        $payload = [];

        foreach ($pattern->fields as $capture => $targets) {
            $value = $this->reference(is_string($matches[$capture] ?? null) ? $matches[$capture] : '');

            if ($value === '') {
                continue;
            }

            foreach ($targets as $target) {
                $payload[$target] = $value;
            }
        }

        $missingFields = array_values(array_filter(
            $pattern->required,
            static fn (string $field): bool => ! array_key_exists($field, $payload),
        ));

        return ['payload' => $payload, 'missingFields' => $missingFields];
    }

    private function reference(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->trim("\"'.,!? ")
            ->replaceMatches('/^the\s+/i', '')
            ->trim()
            ->toString();
    }
}
