<?php

namespace Geocrowd;

use PDO;

/**
 * Nombre maximum d'actions par clé (adresse IP, membre…) sur une fenêtre glissante.
 * Chaque limite a son propre compteur (« bucket ») ; les clés ne sont jamais stockées en clair.
 */
final class RateLimit
{
    private const MAX_WINDOW = 3600;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $bucket,
        private readonly int $max,
        private readonly int $seconds,
    ) {
    }

    /** Refuse la requête si la limite est atteinte. */
    public function check(string $key, string $message): void
    {
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - $this->seconds);
        $this->pdo->prepare('DELETE FROM hits WHERE bucket = ? AND created_at < ?')->execute([$this->bucket, $since]);
        // Aucune limite ne compte au-delà d'une heure : les hachages plus anciens sont effacés, toutes limites confondues.
        $this->pdo->prepare('DELETE FROM hits WHERE created_at < ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - self::MAX_WINDOW)]);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM hits WHERE bucket = ? AND key_hash = ?');
        $stmt->execute([$this->bucket, $this->hash($key)]);
        if ((int) $stmt->fetchColumn() >= $this->max) {
            throw new HttpError(429, $message);
        }
    }

    public function record(string $key): void
    {
        $this->pdo->prepare('INSERT INTO hits (bucket, key_hash, created_at) VALUES (?, ?, ?)')
            ->execute([$this->bucket, $this->hash($key), Db::now()]);
    }

    public function hit(string $key, string $message): void
    {
        $this->check($key, $message);
        $this->record($key);
    }

    private function hash(string $key): string
    {
        $salt = Db::setting($this->pdo, 'ip_salt', fn () => bin2hex(random_bytes(16)));
        return hash('sha256', $salt . $key);
    }
}
