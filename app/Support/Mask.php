<?php

namespace App\Support;

/**
 * Display masks for personal identifiers (board 2026-10-31 item 19): dealer / branch
 * logins see an existing distributor's phone, PAN, Aadhaar and e-mail only in masked
 * form — e.g. 98xxxxxx82, GQXXXXXXXG, XXXX XXXX 1234, ma***@gmail.com. Head Office
 * staff see the real values. Masked strings are display-only and are never persisted.
 */
class Mask
{
    public static function phone(?string $phone): ?string
    {
        $p = trim((string) $phone);
        if ($p === '') {
            return $phone;
        }
        if (strlen($p) <= 4) {
            return str_repeat('x', strlen($p));
        }

        return substr($p, 0, 2) . str_repeat('x', strlen($p) - 4) . substr($p, -2);
    }

    public static function pan(?string $pan): ?string
    {
        $p = strtoupper(trim((string) $pan));
        if ($p === '') {
            return $pan;
        }
        if (strlen($p) <= 3) {
            return str_repeat('X', strlen($p));
        }

        return substr($p, 0, 2) . str_repeat('X', strlen($p) - 3) . substr($p, -1);
    }

    public static function aadhaar(?string $aadhaar): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $aadhaar);
        if ($digits === '') {
            return $aadhaar;
        }
        if (strlen($digits) <= 4) {
            return str_repeat('X', strlen($digits));
        }
        $masked = str_repeat('X', strlen($digits) - 4) . substr($digits, -4);

        return trim(chunk_split($masked, 4, ' '));
    }

    public static function email(?string $email): ?string
    {
        $e = trim((string) $email);
        if ($e === '' || ! str_contains($e, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $e, 2);
        $keep = min(2, strlen($local));

        return substr($local, 0, $keep) . '***@' . $domain;
    }
}
