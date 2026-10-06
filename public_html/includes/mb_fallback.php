<?php

declare(strict_types=1);

/**
 * mb_fallback.php — multibyte-safe string helpers with ASCII fallbacks.
 *
 * Production (Azure) may lack the php-mbstring extension, where any direct
 * mb_* call fatals with "Call to undefined function" and (via
 * bootstrap_errors.php) surfaces as "Something didn't go as planned" on
 * JSON APIs. These wrappers prefer mb_* when available and degrade to
 * single-byte equivalents otherwise so search/filter code never fatals.
 */

if (!function_exists('sk_strtolower')) {
    function sk_strtolower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}

if (!function_exists('sk_strtoupper')) {
    function sk_strtoupper(string $value): string
    {
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($value, 'UTF-8');
        }

        return strtoupper($value);
    }
}

if (!function_exists('sk_strlen')) {
    function sk_strlen(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }
}

if (!function_exists('sk_substr')) {
    function sk_substr(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? mb_substr($value, $start, null, 'UTF-8')
                : mb_substr($value, $start, $length, 'UTF-8');
        }

        return $length === null
            ? substr($value, $start)
            : substr($value, $start, $length);
    }
}

if (!function_exists('sk_strpos_ci')) {
    /**
     * Case-insensitive substring search. Returns byte offset or false,
     * mirroring mb_strpos()/stripos() semantics.
     */
    function sk_strpos_ci(string $haystack, string $needle, int $offset = 0): int|false
    {
        if ($needle === '') {
            return $offset;
        }

        if (function_exists('mb_strpos') && function_exists('mb_strtolower')) {
            return mb_strpos(mb_strtolower($haystack, 'UTF-8'), mb_strtolower($needle, 'UTF-8'), $offset, 'UTF-8');
        }

        return stripos($haystack, $needle, $offset);
    }
}

if (!function_exists('sk_ord')) {
    function sk_ord(string $char): int
    {
        if (function_exists('mb_ord')) {
            return mb_ord($char, 'UTF-8');
        }

        if (class_exists('IntlChar') && method_exists('IntlChar', 'ord')) {
            $code = IntlChar::ord($char);

            if (is_int($code)) {
                return $code;
            }
        }

        return ord($char[0] ?? "\0");
    }
}
