<?php

namespace Geocrowd;

use PDO;

/**
 * Journal des actions du back-office (connexions, modération, schéma, membres, clés, réglages),
 * conservé un an, consultable par les comptes admin.
 */
final class Audit
{
    private const KEEP_DAYS = 365;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** $user : membre à l'origine de l'action, null si inconnu (connexion échouée). */
    public function log(?array $user, string $action, string $target = '', array $details = []): void
    {
        $this->pdo->prepare('INSERT INTO audit (user_id, user_name, action, target, details, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([
                $user['id'] ?? null,
                $user['name'] ?? null,
                $action,
                mb_substr($target, 0, 200),
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                Db::now(),
            ]);
        $this->pdo->prepare('DELETE FROM audit WHERE created_at < ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - self::KEEP_DAYS * 86400)]);
    }

    public function list(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $rows = $this->pdo->query("SELECT * FROM audit ORDER BY id DESC LIMIT $perPage OFFSET $offset")->fetchAll();
        foreach ($rows as &$row) {
            $row['details'] = $row['details'] === null ? null : json_decode($row['details'], true);
        }
        return ['items' => $rows, 'total' => (int) $this->pdo->query('SELECT COUNT(*) FROM audit')->fetchColumn()];
    }
}
