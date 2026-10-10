<?php

namespace Geocrowd;

use PDO;

/**
 * Réglages du site public servi à la racine de l'instance : une carte des points publiés,
 * avec formulaires d'ajout et de proposition de modification. Désactivé par défaut.
 */
final class Front
{
    private const DEFAULTS = [
        'enabled' => false,
        'title' => 'geocrowd',
        'intro' => '',
        // Collections affichées, dans l'ordre ; vide = toutes.
        'collections' => [],
        'submission' => true,
        'edits' => true,
        'center' => [46.6, 2.4],
        'zoom' => 6,
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(): array
    {
        $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE key = 'front'");
        $stmt->execute();
        $saved = json_decode((string) $stmt->fetchColumn(), true);
        return (is_array($saved) ? $saved : []) + self::DEFAULTS;
    }

    public function enabled(): bool
    {
        return $this->get()['enabled'];
    }

    /** Enregistre des réglages validés ; $ids : identifiants des collections existantes. */
    public function save(mixed $input, array $ids): array
    {
        $input = is_array($input) ? $input : [];
        $errors = [];

        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        if ($title === '' || mb_strlen($title) > 100) {
            $errors['title'] = 'Entre 1 et 100 caractères.';
        }
        $intro = is_string($input['intro'] ?? null) ? trim($input['intro']) : '';
        if (mb_strlen($intro) > 2000) {
            $errors['intro'] = '2000 caractères maximum.';
        }

        $center = $input['center'] ?? null;
        $validCenter = is_array($center) && count($center) === 2 && array_is_list($center)
            && (is_int($center[0]) || is_float($center[0])) && (is_int($center[1]) || is_float($center[1]))
            && abs($center[0]) <= 90 && abs($center[1]) <= 180;
        if (!$validCenter) {
            $errors['center'] = 'Position invalide.';
        }
        $zoom = $input['zoom'] ?? null;
        if (!is_int($zoom) || $zoom < 1 || $zoom > 19) {
            $errors['zoom'] = 'Niveau de zoom entre 1 et 19.';
        }

        if ($errors) {
            throw new HttpError(422, 'Certains réglages sont invalides.', $errors);
        }

        $settings = [
            'enabled' => (bool) ($input['enabled'] ?? false),
            'title' => $title,
            'intro' => $intro,
            'collections' => array_values(array_intersect(array_unique((array) ($input['collections'] ?? [])), $ids)),
            'submission' => (bool) ($input['submission'] ?? true),
            'edits' => (bool) ($input['edits'] ?? true),
            'center' => [(float) $center[0], (float) $center[1]],
            'zoom' => $zoom,
        ];
        $this->pdo->prepare("INSERT INTO settings (key, value) VALUES ('front', ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value")
            ->execute([json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        return $settings;
    }
}
