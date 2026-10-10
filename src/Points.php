<?php

namespace Geocrowd;

use PDO;

final class Points
{
    public const STATUSES = ['pending', 'published', 'rejected'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM points WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : throw new HttpError(404, 'Point introuvable.');
    }

    public function isPublished(int $id, string $collection): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM points WHERE id = ? AND collection = ? AND status = 'published'");
        $stmt->execute([$id, $collection]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Points publiés d'une collection, éventuellement limités à une emprise
     * filtrés sur des propriétés ([nom => valeur]) ou sur la présence de propriétés ([nom]).
     * Lus un à un, pour ne pas tout charger en mémoire.
     */
    public function published(string $collection, ?array $bbox, int $limit, array $filters = [], array $present = []): \Generator
    {
        $sql = "SELECT * FROM points WHERE collection = ? AND status = 'published'";
        $params = [$collection];
        if ($bbox) {
            $sql .= ' AND lng BETWEEN ? AND ? AND lat BETWEEN ? AND ?';
            array_push($params, $bbox[0], $bbox[2], $bbox[1], $bbox[3]);
        }
        foreach ($filters as $name => $value) {
            $sql .= ' AND json_extract(properties, ?) = ?';
            array_push($params, '$."' . $name . '"', $value);
        }
        foreach ($present as $name) {
            $sql .= ' AND json_extract(properties, ?) IS NOT NULL';
            $params[] = '$."' . $name . '"';
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY id DESC LIMIT ' . $limit);
        // Liaison typée : SQLite ne considère pas l'entier 1 égal au texte '1'.
        foreach (array_values($params) as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        foreach ($stmt as $row) {
            yield self::hydrate($row);
        }
    }

    public function list(?string $collection, string $status, int $page, int $perPage): array
    {
        $where = 'status = ?';
        $params = [$status];
        if ($collection) {
            $where .= ' AND collection = ?';
            $params[] = $collection;
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM points WHERE $where");
        $count->execute($params);

        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT * FROM points WHERE $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);

        return ['items' => array_map(self::hydrate(...), $stmt->fetchAll()), 'total' => (int) $count->fetchColumn()];
    }

    /** Nombre de points par collection et par statut. */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->pdo->query('SELECT collection, status, COUNT(*) AS n FROM points GROUP BY collection, status') as $row) {
            $counts[$row['collection']][$row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /** Points importés d'une collection, indexés par leur identifiant source. */
    public function imported(string $collection): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM points WHERE collection = ? AND source IS NOT NULL');
        $stmt->execute([$collection]);
        $points = [];
        foreach ($stmt as $row) {
            $points[$row['source']] = self::hydrate($row);
        }
        return $points;
    }

    public function create(string $collection, array $position, array $properties, string $status, ?string $source = null): int
    {
        $now = Db::now();
        $this->pdo->prepare('INSERT INTO points (collection, lat, lng, properties, status, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$collection, $position[0], $position[1], self::encode($properties), $status, $source, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $position, array $properties, string $status): void
    {
        $this->pdo->prepare('UPDATE points SET lat = ?, lng = ?, properties = ?, status = ?, updated_at = ? WHERE id = ?')
            ->execute([$position[0], $position[1], self::encode($properties), $status, Db::now(), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM points WHERE id = ?')->execute([$id]);
    }

    /** Position [lat, lng] lue et validée dans les données reçues. */
    public static function position(array $data): array
    {
        $lat = $data['lat'] ?? null;
        $lng = $data['lng'] ?? null;
        $valid = (is_int($lat) || is_float($lat)) && (is_int($lng) || is_float($lng))
            && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
        return $valid ? [(float) $lat, (float) $lng] : throw new HttpError(422, 'Latitude ou longitude invalide.', ['position' => 'Coordonnées invalides.']);
    }

    public static function status(mixed $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : throw new HttpError(422, 'Statut invalide.');
    }

    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'collection' => $row['collection'],
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
            'properties' => json_decode($row['properties'], true),
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private static function encode(array $properties): string
    {
        return json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
