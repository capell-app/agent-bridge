<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Data;

use Spatie\LaravelData\Data;

/**
 * One deterministic direct-command pattern contributed by a capability provider.
 *
 * Patterns are anchored regular expressions. Named captures are mapped onto
 * payload fields declared by the capability's input schema (or input Data
 * boundary); nothing is semantically expanded, inferred, or guessed.
 */
final class CapabilityCommandPatternData extends Data
{
    /**
     * @param  string  $expression  Anchored PCRE pattern matched against the squished command.
     * @param  string  $phrase  Human-readable phrase shape shown to administrators and trusted clients.
     * @param  array<string, list<string>>  $fields  Named capture => payload fields it populates.
     * @param  list<string>  $required  Payload fields this pattern guarantees to supply.
     */
    public function __construct(
        public readonly string $expression,
        public readonly string $phrase,
        public readonly array $fields = [],
        public readonly array $required = [],
    ) {}

    /**
     * Every payload field this pattern can emit, in declaration order.
     *
     * @return list<string>
     */
    public function payloadFields(): array
    {
        $fields = [];

        foreach ($this->fields as $targets) {
            foreach ($targets as $target) {
                $fields[] = $target;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Client-facing projection. The raw expression is deliberately withheld:
     * trusted clients need the phrase shape and field names, not a regex.
     *
     * @return array{phrase: string, fields: list<string>, required: list<string>}
     */
    public function toPayload(): array
    {
        return [
            'phrase' => $this->phrase,
            'fields' => $this->payloadFields(),
            'required' => $this->required,
        ];
    }
}
