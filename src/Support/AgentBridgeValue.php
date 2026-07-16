<?php

declare(strict_types=1);

namespace Capell\AgentBridge\Support;

use UnexpectedValueException;

final class AgentBridgeValue
{
    public static function integer(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            if ($integer !== false) {
                return $integer;
            }
        }

        throw new UnexpectedValueException(sprintf('%s must be an integer.', $context));
    }

    public static function string(mixed $value, string $context): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new UnexpectedValueException(sprintf('%s must be a string.', $context));
    }

    public static function nonEmptyString(mixed $value, string $context): string
    {
        $string = self::string($value, $context);

        if ($string !== '') {
            return $string;
        }

        throw new UnexpectedValueException(sprintf('%s must not be empty.', $context));
    }

    public static function identifier(mixed $value, string $context): string
    {
        if (is_int($value) || is_string($value)) {
            return (string) $value;
        }

        throw new UnexpectedValueException(sprintf('%s must be an integer or string identifier.', $context));
    }

    public static function nullableInteger(mixed $value, string $context): ?int
    {
        if ($value === null) {
            return null;
        }

        return self::integer($value, $context);
    }

    public static function reference(mixed $value, string $context): int|string|null
    {
        if ($value === null || is_int($value) || is_string($value)) {
            return $value;
        }

        throw new UnexpectedValueException(sprintf('%s must be an integer or string reference.', $context));
    }

    public static function boolean(mixed $value, string $context): bool
    {
        return match ($value) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => throw new UnexpectedValueException(sprintf('%s must be a boolean.', $context)),
        };
    }

    public static function nullableString(mixed $value, string $context): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::string($value, $context);
    }

    /** @return array<string, mixed> */
    public static function associativeArray(mixed $value, string $context): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException(sprintf('%s must be an associative array.', $context));
        }

        $associative = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new UnexpectedValueException(sprintf('%s must use string keys.', $context));
            }

            $associative[$key] = $item;
        }

        return $associative;
    }

    /** @return list<string> */
    public static function stringList(mixed $value, string $context): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException(sprintf('%s must be a list of strings.', $context));
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new UnexpectedValueException(sprintf('%s must contain only strings.', $context));
            }

            $strings[] = $item;
        }

        return $strings;
    }
}
