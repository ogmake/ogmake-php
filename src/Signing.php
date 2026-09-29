<?php

declare(strict_types=1);

namespace Ogmake;

/** Same algorithm as packages/core/src/signing.ts (see packages/laravel-ogmake/src/Signing.php). */
final class Signing
{
    private const EXCLUDED = ['sig', 'debug'];

    /** @param array<string, string|int|float> $params */
    public static function canonicalQuery(array $params): string
    {
        $filtered = [];
        foreach ($params as $key => $value) {
            if (in_array((string) $key, self::EXCLUDED, true)) {
                continue;
            }
            $filtered[(string) $key] = (string) $value;
        }
        // JS default sort = UTF-16 code unit order, not byte order.
        uksort($filtered, [self::class, 'compare']);

        $parts = [];
        foreach ($filtered as $key => $value) {
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode($value);
        }

        return implode('&', $parts);
    }

    public static function decodeSecret(string $secretBase64): string
    {
        $secret = base64_decode($secretBase64, true);
        if ($secret === false) {
            throw new \InvalidArgumentException('invalid_signing_secret');
        }

        return $secret;
    }

    public static function signQuery(string $secretBytes, string $canonical): string
    {
        $mac = hash_hmac('sha256', $canonical, $secretBytes, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /** Lexicographic by code unit, shorter-is-less (PHP's array <=> compares length first, so not usable). */
    public static function compare(string $a, string $b): int
    {
        $ua = self::utf16($a);
        $ub = self::utf16($b);
        $len = min(count($ua), count($ub));
        for ($i = 0; $i < $len; $i++) {
            if ($ua[$i] !== $ub[$i]) {
                return $ua[$i] <=> $ub[$i];
            }
        }

        return count($ua) <=> count($ub);
    }

    /** @return list<int> UTF-16 code units (array comparison is element-wise, shorter-is-less). */
    private static function utf16(string $value): array
    {
        $units = [];
        $bytes = mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
        for ($i = 0, $n = strlen($bytes); $i + 1 < $n; $i += 2) {
            $units[] = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
        }

        return $units;
    }
}
