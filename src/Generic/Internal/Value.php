<?php

declare(strict_types=1);

namespace Gcf\Generic\Internal;

/**
 * Container-shape classification shared by the encoder.
 *
 * PHP's `array` conflates JSON object and JSON array; a non-empty distinguishes
 * cleanly via array_is_list(), but an *empty* array is inherently ambiguous
 * (empty object vs empty array both surface as `[]`). This class resolves that
 * ambiguity with an explicit convention: an empty array defaults to a JSON
 * array; to encode an explicit empty *object*, pass a `\stdClass` instance
 * (as `json_decode($x, false)` naturally produces for `{}`). Any `\stdClass`
 * — empty or not — is always treated as an object, and `get_object_vars()`
 * preserves PHP's insertion order, matching the ordering guarantee associative
 * arrays already provide.
 */
final class Value
{
    private function __construct()
    {
    }

    public const OBJECT = 'object';
    public const ARR = 'array';
    public const SCALAR = 'scalar';
    public const NULL = 'null';

    public static function classify(mixed $v): string
    {
        if ($v === null) {
            return self::NULL;
        }
        if ($v instanceof \stdClass) {
            return self::OBJECT;
        }
        if (is_array($v)) {
            if ($v === []) {
                return self::ARR;
            }

            return array_is_list($v) ? self::ARR : self::OBJECT;
        }

        return self::SCALAR;
    }

    public static function isObject(mixed $v): bool
    {
        return self::classify($v) === self::OBJECT;
    }

    public static function isArray(mixed $v): bool
    {
        return self::classify($v) === self::ARR;
    }

    /**
     * Normalizes an object-classified value (stdClass or associative array)
     * to a plain associative array for iteration, preserving key order.
     *
     * @return array<string, mixed>
     */
    public static function toAssoc(mixed $v): array
    {
        if ($v instanceof \stdClass) {
            return get_object_vars($v);
        }
        if (is_array($v)) {
            return $v;
        }

        return [];
    }

    /**
     * Normalizes an array-classified value to a plain list array.
     *
     * @return list<mixed>
     */
    public static function toList(mixed $v): array
    {
        return is_array($v) ? array_values($v) : [];
    }

    public static function indent(int $depth): string
    {
        return str_repeat('  ', $depth);
    }
}
