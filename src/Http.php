<?php

namespace Geocrowd;

final class Http
{
    private const MAX_BODY = 65536;

    /**
     * Politique de sécurité des pages : scripts, styles et polices de l'instance uniquement,
     * fonds de carte et recherche de lieux OpenStreetMap. À garder identique dans .htaccess.
     */
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob: https://tile.openstreetmap.org; "
        . "font-src 'self'; connect-src 'self' https://nominatim.openstreetmap.org; object-src 'none'; base-uri 'self'; "
        . "form-action 'self'; frame-ancestors 'none'";

    /** En-têtes de sécurité de toutes les réponses de PHP. */
    public static function securityHeaders(): void
    {
        header('Content-Security-Policy: ' . self::CSP);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function query(string $key): ?string
    {
        $value = $_GET[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Corps JSON de la requête, sous forme de tableau. */
    public static function body(): array
    {
        $raw = file_get_contents('php://input', length: self::MAX_BODY + 1);
        if ($raw === '' || $raw === false) {
            return [];
        }
        if (strlen($raw) > self::MAX_BODY) {
            throw new HttpError(413, 'Requête trop volumineuse.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new HttpError(400, 'Le corps de la requête doit être un objet JSON.');
        }
        return $data;
    }

    /**
     * Données d'un formulaire de point : JSON pur, ou multipart avec le JSON
     * dans le champ « data » et les images dans des champs nommés comme les propriétés.
     */
    public static function input(): array
    {
        if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) {
            return [self::body(), []];
        }
        if (!$_POST && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new HttpError(413, 'Requête trop volumineuse.');
        }
        $data = json_decode($_POST['data'] ?? '{}', true);
        if (!is_array($data)) {
            throw new HttpError(400, 'Le champ « data » doit contenir un objet JSON.');
        }
        return [$data, self::files()];
    }

    /** $_FILES réorganisé en [champ => [fichier, …]], sans les champs vides. */
    private static function files(): array
    {
        $files = [];
        foreach ($_FILES as $field => $file) {
            foreach ((array) $file['error'] as $i => $error) {
                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $files[$field][] = [
                    'tmp_name' => ((array) $file['tmp_name'])[$i],
                    'size' => ((array) $file['size'])[$i],
                    'error' => $error,
                ];
            }
        }
        return $files;
    }

    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** FeatureCollection GeoJSON envoyée au fil de l'eau, Feature par Feature. */
    public static function features(iterable $features): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo '{"type":"FeatureCollection","features":[';
        foreach ($features as $i => $feature) {
            echo ($i ? ',' : '') . json_encode($feature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        echo ']}';
    }

    public static function error(HttpError $e): void
    {
        $payload = ['error' => $e->getMessage()];
        if ($e->details) {
            $payload['details'] = $e->details;
        }
        self::json($payload, $e->getCode());
    }

    /**
     * Clé de limite de débit du client : son adresse IPv4, ou le bloc /64 de son adresse IPv6
     * (une seule connexion dispose de tout un /64 et pourrait sinon changer d'adresse à chaque requête).
     */
    public static function clientKey(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $packed = @inet_pton($ip);
        return $packed !== false && strlen($packed) === 16 ? bin2hex(substr($packed, 0, 8)) . '::/64' : $ip;
    }
}
