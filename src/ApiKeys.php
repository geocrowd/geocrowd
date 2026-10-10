<?php

namespace Geocrowd;

use PDO;

/**
 * Clés d'accès à l'API publique (quand config.php l'exige).
 * Une clé limitée à des domaines n'est acceptée que depuis une page de ces domaines :
 * elle peut figurer dans le code JavaScript d'un site. Une clé sans domaine sert aux
 * échanges de serveur à serveur et doit rester secrète.
 */
final class ApiKeys
{
    private const DOMAIN_PATTERN = '/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $keys = $this->pdo->query('SELECT id, name, prefix, domains, created_at, last_used_at FROM api_keys ORDER BY name')->fetchAll();
        return array_map(fn ($key) => ['domains' => json_decode($key['domains'], true)] + $key, $keys);
    }

    /** Crée une clé et la renvoie (affichée une seule fois, seul son hachage est conservé). */
    public function create(mixed $name, mixed $domains): string
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || mb_strlen($name) > 100) {
            throw new HttpError(422, 'Nom invalide.', ['name' => 'Entre 1 et 100 caractères.']);
        }
        $domains = self::domains($domains);

        $key = 'gc_' . bin2hex(random_bytes(20));
        $this->pdo->prepare('INSERT INTO api_keys (name, key_hash, prefix, domains, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, hash('sha256', $key), substr($key, 0, 10), json_encode($domains), Db::now()]);
        return $key;
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$id]);
    }

    /** Clé valide pour la page d'origine de la requête, sinon erreur 401 ou 403. */
    public function authorize(?string $key, ?string $origin): array
    {
        $stmt = $this->pdo->prepare('SELECT id, domains FROM api_keys WHERE key_hash = ?');
        $stmt->execute([hash('sha256', (string) $key)]);
        $row = $stmt->fetch() ?: throw new HttpError(401, 'Clé d\'API manquante ou invalide.');
        $domains = json_decode($row['domains'], true);

        if ($domains && !self::matches(self::host($origin), $domains)) {
            throw new HttpError(403, 'Domaine non autorisé pour cette clé.');
        }

        // Date de dernière utilisation, mise à jour au plus une fois par minute.
        $this->pdo->prepare('UPDATE api_keys SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)')
            ->execute([Db::now(), $row['id'], gmdate('Y-m-d\TH:i:s\Z', time() - 60)]);
        return ['id' => (int) $row['id'], 'domains' => $domains];
    }

    /** Une clé autorise-t-elle cette origine ? (requêtes préliminaires CORS, qui ne portent pas la clé) */
    public function allowsOrigin(string $origin): bool
    {
        $host = self::host($origin);
        foreach ($this->pdo->query('SELECT domains FROM api_keys') as $row) {
            if (self::matches($host, json_decode($row['domains'], true))) {
                return true;
            }
        }
        return false;
    }

    private static function host(?string $url): ?string
    {
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;
        return $host ? strtolower($host) : null;
    }

    private static function matches(?string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            $match = str_starts_with($domain, '*.') ? str_ends_with((string) $host, substr($domain, 1)) : $host === $domain;
            if ($host && $match) {
                return true;
            }
        }
        return false;
    }

    /** Domaines saisis (liste ou texte, un par ligne), adresses complètes acceptées. */
    private static function domains(mixed $input): array
    {
        $lines = is_array($input) ? $input : preg_split('/[\s,]+/', (string) $input);
        $domains = [];
        foreach ($lines as $line) {
            $line = strtolower(trim((string) $line));
            if ($line === '') {
                continue;
            }
            $domain = str_contains($line, '://') ? (string) parse_url($line, PHP_URL_HOST) : preg_replace('/[:\/].*$/', '', $line);
            if (!preg_match(self::DOMAIN_PATTERN, $domain)) {
                throw new HttpError(422, 'Domaine invalide.', ['domains' => "Domaine invalide : $line"]);
            }
            $domains[] = $domain;
        }
        return array_values(array_unique($domains));
    }
}
