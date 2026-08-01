<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use Capell\AgentBridge\Data\CapabilityCommandPatternData;
use Capell\AgentBridge\Data\CapabilityData;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Fails capability registration when contributed command patterns could emit a
 * payload field the capability's input boundary does not declare.
 *
 * This is a developer-time guard: a pattern that silently produced an unknown
 * field would be rejected much later, inside preview validation, with a far
 * less useful message.
 */
final class AgentBridgeCommandPatternValidator
{
    public function validate(CapabilityData $capability): void
    {
        if ($capability->commandPatterns === []) {
            return;
        }

        $allowedFields = $this->allowedFields($capability);

        foreach ($capability->commandPatterns as $index => $pattern) {
            $this->validatePattern($capability, $pattern, $index, $allowedFields);
        }
    }

    /** @param list<string>|null $allowedFields */
    private function validatePattern(
        CapabilityData $capability,
        CapabilityCommandPatternData $pattern,
        int $index,
        ?array $allowedFields,
    ): void {
        if ($pattern->phrase === '') {
            $this->fail($capability, $index, 'phrase', 'must not be empty.');
        }

        $this->validateExpression($capability, $pattern, $index);

        foreach ($pattern->fields as $capture => $targets) {
            if ($targets === []) {
                $this->fail($capability, $index, 'fields', sprintf('capture [%s] maps to no payload field.', $capture));
            }

            foreach ($targets as $target) {
                if ($allowedFields !== null && ! in_array($target, $allowedFields, true)) {
                    $this->fail($capability, $index, 'fields', sprintf(
                        'payload field [%s] is not declared by the capability input boundary.',
                        $target,
                    ));
                }
            }
        }

        $emitted = $pattern->payloadFields();

        foreach ($pattern->required as $requiredField) {
            if (! in_array($requiredField, $emitted, true)) {
                $this->fail($capability, $index, 'required', sprintf(
                    'payload field [%s] is claimed as required but no capture supplies it.',
                    $requiredField,
                ));
            }
        }
    }

    private function validateExpression(CapabilityData $capability, CapabilityCommandPatternData $pattern, int $index): void
    {
        $expression = $pattern->expression;

        try {
            $matched = @preg_match($expression, '');
        } catch (Throwable) {
            $matched = false;
        }

        if ($matched === false) {
            $this->fail($capability, $index, 'expression', 'is not a valid regular expression.');
        }

        if (! $this->isAnchored($expression)) {
            $this->fail($capability, $index, 'expression', 'must be anchored with ^ and $.');
        }
    }

    /** Whether the pattern body — delimiters and modifiers stripped — is anchored at both ends. */
    private function isAnchored(string $expression): bool
    {
        if (preg_match('/^(.)(.*)\1[a-zA-Z]*$/s', $expression, $matches) !== 1) {
            return false;
        }

        $body = $matches[2];

        return str_starts_with($body, '^') && str_ends_with($body, '$');
    }

    /**
     * Payload fields the capability accepts, or null when the capability
     * declares neither an input schema nor an input Data class and therefore
     * cannot be checked.
     *
     * @return list<string>|null
     */
    private function allowedFields(CapabilityData $capability): ?array
    {
        $properties = $capability->inputSchema['properties'] ?? null;

        if (is_array($properties) && $properties !== []) {
            return array_values(array_filter(array_keys($properties), 'is_string'));
        }

        if ($capability->inputDataClass === null || ! class_exists($capability->inputDataClass)) {
            return null;
        }

        $reflection = new ReflectionClass($capability->inputDataClass);

        return array_values(array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            array_filter(
                $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
                static fn (ReflectionProperty $property): bool => ! $property->isStatic(),
            ),
        ));
    }

    private function fail(CapabilityData $capability, int $index, string $field, string $problem): never
    {
        throw new InvalidArgumentException(sprintf(
            'Agent Bridge capability [%s] command pattern #%d field [%s] %s',
            $capability->key,
            $index,
            $field,
            $problem,
        ));
    }
}
