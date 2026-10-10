<?php

namespace Geocrowd;

use PDO;

/**
 * Propositions de modification d'un point publié. Une proposition ne garde que les
 * champs qui changent : appliquée plus tard, elle n'écrase pas les autres corrections
 * faites entre-temps.
 */
final class Edits
{
    private const MAX_COMMENT = 1000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Collections $collections,
        private readonly Points $points,
        private readonly Images $images,
    ) {
    }

    /**
     * Changements proposés sur un point ([champ => nouvelle valeur, null pour vider]).
     * Seuls les champs fournis changent et sont validés (un point importé peut contenir
     * des valeurs hors des options de la collection) ; les nouvelles images sont enregistrées.
     * Pour un champ image, la valeur liste les images actuelles à garder (noms ou URL de
     * l'API) ; sans valeur, les fichiers envoyés s'ajoutent aux images actuelles.
     */
    public function changes(array $collection, array $point, array $proposed, array $files): array
    {
        $current = $point['properties'];
        $proposed = array_intersect_key($proposed, array_flip(array_column($collection['fields'], 'name')));
        foreach (Collections::imageFields($collection) as $name) {
            if (array_key_exists($name, $proposed)) {
                $proposed[$name] = array_map(fn ($image) => basename((string) parse_url((string) $image, PHP_URL_PATH)), (array) $proposed[$name]);
            } elseif (isset($files[$name])) {
                $proposed[$name] = $current[$name] ?? [];
            }
        }

        $collection = self::only($collection, array_keys($proposed));
        $clean = $this->collections->validate($collection, $proposed, $files, $current);
        $changes = [];
        foreach ($collection['fields'] as $field) {
            $name = $field['name'];
            if (($clean[$name] ?? null) !== ($current[$name] ?? null)) {
                $changes[$name] = $clean[$name] ?? null;
            }
        }
        return $changes;
    }

    public function create(int $pointId, ?array $position, array $changes, ?string $comment): int
    {
        $this->pdo->prepare('INSERT INTO edits (point_id, lat, lng, changes, comment, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$pointId, $position[0] ?? null, $position[1] ?? null, json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $comment, Db::now()]);
        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM edits WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : throw new HttpError(404, 'Proposition introuvable.');
    }

    public function list(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $rows = $this->pdo->query("SELECT * FROM edits ORDER BY id LIMIT $perPage OFFSET $offset")->fetchAll();
        return ['items' => array_map(self::hydrate(...), $rows), 'total' => $this->count()];
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM edits')->fetchColumn();
    }

    /**
     * Applique des changements à un point. Les champs modifiés sont revalidés (un point
     * référencé a pu être dépublié entre-temps) ; les images qui ne servent plus sont supprimées.
     */
    public function applyTo(array $point, ?array $position, array $changes): void
    {
        $collection = self::only($this->collections->get($point['collection']), array_keys($changes));
        $existing = $point['properties'];
        foreach (Collections::imageFields($collection) as $name) {
            $existing[$name] = array_merge($existing[$name] ?? [], $changes[$name] ?? []);
        }
        $clean = $this->collections->validate($collection, $changes, [], $existing);

        $properties = $point['properties'];
        foreach ($collection['fields'] as $field) {
            $name = $field['name'];
            if (isset($clean[$name])) {
                $properties[$name] = $clean[$name];
            } else {
                unset($properties[$name]);
            }
        }
        $this->points->update($point['id'], $position ?? [$point['lat'], $point['lng']], $properties, $point['status']);

        foreach (Collections::imageFields($collection) as $name) {
            $this->images->delete(array_diff($existing[$name], $properties[$name] ?? []));
        }
    }

    public function apply(int $id): void
    {
        $edit = $this->find($id);
        $this->applyTo($this->points->find($edit['point_id']), $edit['position'], $edit['changes']);
        $this->pdo->prepare('DELETE FROM edits WHERE id = ?')->execute([$id]);
    }

    /** Refuse une proposition et supprime les images qu'elle apportait. */
    public function reject(int $id): void
    {
        $edit = $this->find($id);
        $point = $this->points->find($edit['point_id']);
        $this->deleteNewImages($point, $edit['changes']);
        $this->pdo->prepare('DELETE FROM edits WHERE id = ?')->execute([$id]);
    }

    /** Avant la suppression d'un point : images apportées par ses propositions (supprimées avec lui). */
    public function discardForPoint(array $point): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM edits WHERE point_id = ?');
        $stmt->execute([$point['id']]);
        foreach ($stmt as $row) {
            $this->deleteNewImages($point, self::hydrate($row)['changes']);
        }
    }

    public static function comment(mixed $comment): ?string
    {
        $comment = is_string($comment) ? trim($comment) : null;
        if ($comment !== null && mb_strlen($comment) > self::MAX_COMMENT) {
            throw new HttpError(422, 'Commentaire trop long.', ['comment' => self::MAX_COMMENT . ' caractères maximum.']);
        }
        return $comment ?: null;
    }

    /** Collection réduite à certains champs, pour ne valider que ceux qui changent. */
    private static function only(array $collection, array $names): array
    {
        return ['fields' => array_values(array_filter($collection['fields'], fn ($f) => in_array($f['name'], $names, true)))] + $collection;
    }

    private function deleteNewImages(array $point, array $changes): void
    {
        foreach (Collections::imageFields($this->collections->get($point['collection'])) as $name) {
            $this->images->delete(array_diff($changes[$name] ?? [], $point['properties'][$name] ?? []));
        }
    }

    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'point_id' => (int) $row['point_id'],
            'position' => $row['lat'] === null ? null : [(float) $row['lat'], (float) $row['lng']],
            'changes' => json_decode($row['changes'], true),
            'comment' => $row['comment'],
            'created_at' => $row['created_at'],
        ];
    }
}
