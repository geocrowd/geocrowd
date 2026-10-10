<?php

namespace Geocrowd;

/**
 * Codes à usage unique des applications d'authentification (TOTP, RFC 6238).
 * Le code dépend d'un secret partagé et de l'heure : aucun envoi d'e-mail ou de SMS.
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    // Pas de temps acceptés avant et après l'heure du serveur, pour tolérer un léger décalage d'horloge.
    private const WINDOW = 1;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Nouveau secret, encodé en base 32 comme l'attendent les applications. */
    public static function secret(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        return implode('', array_map(fn ($chunk) => self::BASE32[bindec($chunk)], str_split($bits, 5)));
    }

    /** Adresse otpauth:// à encoder dans le QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode("$issuer:$account") . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Pas de temps du code s'il est valide, sinon null. */
    public static function verify(string $secret, string $code, ?int $time = null): ?int
    {
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $key = self::decode($secret);
        $now = intdiv($time ?? time(), self::PERIOD);
        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if (hash_equals(self::code($key, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    private static function code(string $key, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0xf;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $bits .= str_pad(decbin(strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        }
        return implode('', array_map(fn ($byte) => chr(bindec($byte)), str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8)));
    }
}
