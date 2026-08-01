<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The four explicit results of interpreting a direct admin command.
 *
 * Agent Bridge never manufactures a capability key or payload: only
 * {@see self::Exact} carries an executable proposal, and even then the proposal
 * still has to survive preview, policy recheck, and confirmation.
 */
enum CommandInterpretationOutcomeEnum: string implements HasLabel
{
    /** One deterministic pattern produced a complete, schema-compatible payload. */
    case Exact = 'exact';

    /** No pattern matched, but one visible capability ranked clearly above the rest. */
    case Suggested = 'suggested';

    /** Several capabilities are plausible, or a required value could not be extracted safely. */
    case Ambiguous = 'ambiguous';

    /** No visible capability has meaningful evidence of a match. */
    case Unmatched = 'unmatched';

    public function getLabel(): string
    {
        return (string) match ($this) {
            self::Exact => __('capell-agent-bridge::admin.command_outcomes.exact'),
            self::Suggested => __('capell-agent-bridge::admin.command_outcomes.suggested'),
            self::Ambiguous => __('capell-agent-bridge::admin.command_outcomes.ambiguous'),
            self::Unmatched => __('capell-agent-bridge::admin.command_outcomes.unmatched'),
        };
    }

    /** Only an exact interpretation may pre-select a capability and payload. */
    public function isExact(): bool
    {
        return $this === self::Exact;
    }

    /** Whether the administrator must pick a capability before anything can be previewed. */
    public function requiresSelection(): bool
    {
        return $this === self::Suggested || $this === self::Ambiguous;
    }
}
