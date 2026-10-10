<?php

namespace Geocrowd;

use PDO;

/**
 * Collections et leurs champs, enregistrées en base et modifiées par l'éditeur de schéma
 * du back-office. Valide aussi les propriétés des points.
 */
final class Collections
{
    private const DEFAULT_MAX_LENGTH = ['text' => 255, 'textarea' => 2000, 'url' => 500];
    private const DEFAULT_IMAGES = 1;
    private const DEFAULT_IMAGE_MB = 5;

    private array $items = [];

    /** $legacyDir : dossier des anciennes définitions en fichiers JSON, importées une fois. */
    public function __construct(private readonly PDO $pdo, string $legacyDir, private readonly Images $images, private readonly Points $points)
    {
        Db::setting($pdo, 'collections_imported', function () use ($legacyDir) {
            $this->importFiles($legacyDir);
            return '1';
        });
        foreach ($pdo->query('SELECT definition FROM collections ORDER BY created_at, id') as $row) {
            $collection = json_decode($row['definition'], true);
            $this->items[$collection['id']] = $collection;
        }
    }

    public function all(): array
    {
        return array_values($this->items);
    }

    public function get(string $id): array
    {
        return $this->items[$id] ?? throw new HttpError(404, 'Collection introuvable.');
    }

    /**
     * Crée ($id null) ou modifie une collection. Les valeurs des champs retirés sont
     * effacées des points et des propositions de modification, images comprises.
     */
    public function save(mixed $input, ?string $id = null): array
    {
        $existing = $id === null ? null : $this->get($id);
        $collection = Schema::normalize($input, $existing, array_keys($this->items));
        $removed = $existing ? array_values(array_diff(array_column($existing['fields'], 'name'), array_column($collection['fields'], 'name'))) : [];

        $this->pdo->beginTransaction();
        try {
            $now = Db::now();
            $this->pdo->prepare('INSERT INTO collections (id, definition, created_at, updated_at) VALUES (?, ?, ?, ?)
                ON CONFLICT (id) DO UPDATE SET definition = excluded.definition, updated_at = excluded.updated_at')
                ->execute([$collection['id'], self::encode($collection), $now, $now]);
            $images = $removed ? $this->removeFields($existing, $removed) : [];
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->images->delete($images);
        return $this->items[$collection['id']] = $collection;
    }

    /** Supprime une collection avec ses points, leurs images et les propositions en attente. */
    public function delete(string $id): void
    {
        $collection = $this->get($id);
        foreach ($this->items as $other) {
            foreach (self::refFields($other) as $field) {
                if ($other['id'] !== $id && $field['collection'] === $id) {
                    throw new HttpError(409, "Le champ « {$field['label']} » de la collection « {$other['name']} » désigne des points de celle-ci : modifiez-le d'abord.");
                }
            }
        }

        $this->pdo->beginTransaction();
        try {
            $images = $this->removeFields($collection, self::imageFields($collection));
            // Les propositions de modification sont supprimées avec leurs points.
            $this->pdo->prepare('DELETE FROM points WHERE collection = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM collections WHERE id = ?')->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->images->delete($images);
        unset($this->items[$id]);
    }

    /** Nombre de points (tous statuts) ayant une valeur, par champ. */
    public function usage(string $id): array
    {
        $collection = $this->get($id);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM points WHERE collection = ? AND json_extract(properties, ?) IS NOT NULL');
        $usage = [];
        foreach ($collection['fields'] as $field) {
            $stmt->execute([$id, '$."' . $field['name'] . '"']);
            $usage[$field['name']] = (int) $stmt->fetchColumn();
        }
        return $usage;
    }

    /**
     * Retire des champs des points d'une collection et de leurs propositions de modification.
     * Renvoie les images que portaient ces champs, à supprimer une fois la transaction validée.
     */
    private function removeFields(array $collection, array $names): array
    {
        $imageNames = array_intersect(self::imageFields($collection), $names);
        $images = [];
        $sources = [
            ['points', 'properties', 'collection = ?'],
            ['edits', 'changes', 'point_id IN (SELECT id FROM points WHERE collection = ?)'],
        ];
        foreach ($sources as [$table, $column, $where]) {
            $select = $this->pdo->prepare("SELECT id, $column FROM $table WHERE $where");
            $select->execute([$collection['id']]);
            $update = $this->pdo->prepare("UPDATE $table SET $column = ? WHERE id = ?");
            foreach ($select->fetchAll() as $row) {
                $data = json_decode($row[$column], true);
                if (!array_intersect_key($data, array_flip($names))) {
                    continue;
                }
                foreach ($imageNames as $name) {
                    $images = array_merge($images, (array) ($data[$name] ?? []));
                }
                $update->execute([self::encode(array_diff_key($data, array_flip($names)) ?: new \stdClass()), $row['id']]);
            }
        }
        return $images;
    }

    /** Anciennes définitions collections/<id>.json, importées au premier lancement de cette version. */
    private function importFiles(string $dir): void
    {
        $files = glob($dir . '/*.json') ?: [];
        $ids = array_map(fn ($file) => basename($file, '.json'), $files);
        foreach ($files as $file) {
            $id = basename($file, '.json');
            $definition = (json_decode(file_get_contents($file), true) ?: []) + ['name' => $id];
            // Le libellé était facultatif dans les fichiers.
            $definition['fields'] = array_map(fn ($f) => is_array($f) ? $f + ['label' => $f['name'] ?? ''] : $f, (array) ($definition['fields'] ?? []));
            try {
                $collection = Schema::normalize(['id' => $id] + $definition, null, array_diff($ids, [$id]));
            } catch (HttpError $e) {
                throw new \RuntimeException("Collection invalide : $file " . json_encode($e->details, JSON_UNESCAPED_UNICODE));
            }
            $now = Db::now();
            $this->pdo->prepare('INSERT OR IGNORE INTO collections (id, definition, created_at, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$id, self::encode($collection), $now, $now]);
        }
    }

    private static function encode(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function imageFields(array $collection): array
    {
        return array_column(array_filter($collection['fields'], fn ($f) => $f['type'] === 'image'), 'name');
    }

    /** Champs qui désignent un point d'une autre collection. */
    public static function refFields(array $collection): array
    {
        return array_values(array_filter($collection['fields'], fn ($f) => $f['type'] === 'ref'));
    }

    /**
     * Valide les propriétés d'un point et enregistre ses nouvelles images.
     * Seuls les champs déclarés sont conservés. Pour un champ image, la valeur
     * reçue liste les images existantes à garder ; les fichiers envoyés s'y ajoutent.
     */
    public function validate(array $collection, array $properties, array $files = [], array $existing = []): array
    {
        $clean = [];
        $errors = [];
        $uploads = [];

        foreach ($collection['fields'] as $field) {
            $name = $field['name'];

            if ($field['type'] === 'image') {
                $kept = array_values(array_intersect((array) ($properties[$name] ?? []), $existing[$name] ?? []));
                $new = $files[$name] ?? [];
                $error = self::checkImages($field, $kept, $new);
                if ($error) {
                    $errors[$name] = $error;
                } elseif ($kept || $new) {
                    $clean[$name] = $kept;
                    $uploads[$name] = $new;
                }
                continue;
            }

            $value = $properties[$name] ?? null;
            if ($value === null || $value === '' || $value === []) {
                if (!empty($field['required'])) {
                    $errors[$name] = 'Champ obligatoire.';
                }
                continue;
            }

            $error = self::checkValue($field, $value)
                ?? ($field['type'] === 'ref' && !$this->points->isPublished($value, $field['collection']) ? 'Point introuvable.' : null);
            if ($error) {
                $errors[$name] = $error;
            } else {
                $clean[$name] = match (true) {
                    is_string($value) => trim($value),
                    $field['type'] === 'multiselect' => array_values(array_unique($value)),
                    default => $value,
                };
            }
        }

        if ($errors) {
            throw new HttpError(422, 'Certains champs sont invalides.', $errors);
        }
        foreach ($uploads as $name => $new) {
            $clean[$name] = array_merge($clean[$name], $this->images->store($new));
        }
        return $clean;
    }

    private static function checkImages(array $field, array $kept, array $new): ?string
    {
        $max = $field['max'] ?? self::DEFAULT_IMAGES;
        $count = count($kept) + count($new);
        if ($count === 0) {
            return empty($field['required']) ? null : 'Image obligatoire.';
        }
        if ($count > $max) {
            return "$max image(s) maximum.";
        }
        foreach ($new as $file) {
            $error = Images::check($file, $field['maxSize'] ?? self::DEFAULT_IMAGE_MB);
            if ($error) {
                return $error;
            }
        }
        return null;
    }

    private static function checkValue(array $field, mixed $value): ?string
    {
        switch ($field['type']) {
            case 'boolean':
                return is_bool($value) ? null : 'Valeur oui/non attendue.';
            case 'number':
                if (!is_int($value) && !is_float($value)) {
                    return 'Nombre attendu.';
                }
                if (isset($field['min']) && $value < $field['min']) {
                    return "Minimum : {$field['min']}.";
                }
                if (isset($field['max']) && $value > $field['max']) {
                    return "Maximum : {$field['max']}.";
                }
                return null;
            case 'select':
                return in_array($value, $field['options'] ?? [], true) ? null : 'Valeur non autorisée.';
            case 'multiselect':
                $valid = is_array($value) && array_is_list($value)
                    && !array_filter($value, fn ($v) => !in_array($v, $field['options'] ?? [], true));
                return $valid ? null : 'Valeur non autorisée.';
            case 'ref':
                return is_int($value) && $value > 0 ? null : 'Numéro de point attendu.';
            case 'date':
                $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                return $date && $date->format('Y-m-d') === $value ? null : 'Date attendue (AAAA-MM-JJ).';
        }

        if (!is_string($value)) {
            return 'Texte attendu.';
        }
        $max = $field['maxLength'] ?? self::DEFAULT_MAX_LENGTH[$field['type']];
        if (mb_strlen($value) > $max) {
            return "$max caractères maximum.";
        }
        // http(s) seulement : filter_var accepte aussi javascript:// ou data://, dangereux en lien.
        if ($field['type'] === 'url' && (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value))) {
            return 'Adresse web invalide.';
        }
        return null;
    }
}
