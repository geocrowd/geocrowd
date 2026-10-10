<?php

namespace Geocrowd;

/** API publique : lecture des points publiés et soumission. */
return static function (Router $router, App $app, string $base): void {
    // Point au format GeoJSON, avec les URL complètes de ses images.
    $feature = function (array $collection, array $point) use ($base) {
        $properties = $point['properties'];
        foreach (Collections::imageFields($collection) as $name) {
            if (isset($properties[$name])) {
                $properties[$name] = array_map(fn ($file) => "$base/api/files/$file", $properties[$name]);
            }
        }
        $properties['created_at'] = $point['created_at'];
        return [
            'type' => 'Feature',
            'id' => $point['id'],
            'geometry' => ['type' => 'Point', 'coordinates' => [$point['lng'], $point['lat']]],
            'properties' => $properties,
        ];
    };

    // Description d'une collection, avec son nombre de points publiés.
    $describe = function (array $c) use ($app) {
        static $counts = null;
        $counts ??= $app->points->counts();
        return [
            'id' => $c['id'],
            'name' => $c['name'],
            'description' => $c['description'] ?? '',
            'public_submission' => $c['public_submission'],
            'public_edit' => $c['public_edit'],
            'fields' => $c['fields'],
            'count' => $counts[$c['id']]['published'] ?? 0,
        ];
    };

    // Point publié d'une collection, ou erreur 404.
    $published = function (string $id, string $pointId) use ($app) {
        $point = ctype_digit($pointId) ? $app->points->find((int) $pointId) : null;
        if (!$point || $point['collection'] !== $id || $point['status'] !== 'published') {
            throw new HttpError(404, 'Point introuvable.');
        }
        return $point;
    };

    /**
     * Garde-fous des envois publics (soumissions et propositions de modification) : limite par
     * adresse IP, limite globale par heure, taille de la file de modération, place des images.
     */
    $rateLimit = function (array $files = []) use ($app) {
        $config = $app->config;
        (new RateLimit($app->pdo, 'submissions', $config['submissions_per_hour'], 3600))
            ->hit(Http::clientKey(), 'Trop de soumissions, réessayez plus tard.');
        (new RateLimit($app->pdo, 'submissions-all', $config['submissions_total_per_hour'] ?? 500, 3600))
            ->hit('all', 'Trop de soumissions sur l\'ensemble du site, réessayez plus tard.');

        $pending = (int) $app->pdo->query("SELECT (SELECT COUNT(*) FROM points WHERE status = 'pending') + (SELECT COUNT(*) FROM edits)")->fetchColumn();
        if ($pending >= ($config['max_pending'] ?? 2000)) {
            throw new HttpError(429, 'La file de modération est pleine, réessayez plus tard.');
        }
        $incoming = array_sum(array_column(array_merge([], ...array_values($files)), 'size'));
        if ($incoming && $app->images->size() + $incoming > ($config['uploads_max_mb'] ?? 2000) * 1024 * 1024) {
            throw new HttpError(507, 'L\'espace réservé aux images est plein.');
        }
    };

    // Configuration du site public : collections affichées et envois permis.
    $router->add('GET', '/api/front', function () use ($app, $describe) {
        $front = $app->front->get();
        if (!$front['enabled']) {
            throw new HttpError(404, 'Le site public n\'est pas activé.');
        }
        $all = array_column($app->collections->all(), null, 'id');
        $collections = [];
        foreach ($front['collections'] ?: array_keys($all) as $id) {
            if ($c = $all[$id] ?? null) {
                $collections[] = $describe($c) + [
                    'can_submit' => $front['submission'] && $c['public_submission'],
                    'can_edit' => $front['edits'] && $c['public_edit'],
                ];
            }
        }
        Http::json([
            'title' => $front['title'],
            'intro' => $front['intro'],
            'center' => $front['center'],
            'zoom' => $front['zoom'],
            'collections' => $collections,
        ]);
    });

    $router->add('GET', '/api/collections', function () use ($app, $describe) {
        Http::json(array_map($describe, $app->collections->all()));
    });

    $router->add('GET', '/api/collections/{id}', function (string $id) use ($app, $describe) {
        Http::json($describe($app->collections->get($id)));
    });

    $router->add('GET', '/api/collections/{id}/points', function (string $id) use ($app, $feature) {
        // Requête la plus coûteuse : limitée par adresse IP.
        (new RateLimit($app->pdo, 'reads', $app->config['reads_per_minute'] ?? 120, 60))
            ->hit(Http::clientKey(), 'Trop de requêtes, réessayez dans une minute.');
        $collection = $app->collections->get($id);
        $bbox = Http::query('bbox');
        if ($bbox !== null) {
            $bbox = array_map('floatval', explode(',', $bbox));
            if (count($bbox) !== 4) {
                throw new HttpError(400, 'bbox attendu : ouest,sud,est,nord.');
            }
        }

        // Filtre sur un champ de référence : ?banc=12
        $filters = [];
        foreach (Collections::refFields($collection) as $field) {
            $value = Http::query($field['name']);
            if ($value !== null) {
                $filters[$field['name']] = ctype_digit($value) ? (int) $value : throw new HttpError(400, "{$field['name']} : numéro de point attendu.");
            }
        }

        // Points dont un champ est renseigné : ?with=constats
        $present = [];
        $with = Http::query('with');
        if ($with !== null) {
            $present[] = in_array($with, array_column($collection['fields'], 'name'), true) ? $with : throw new HttpError(400, 'with : champ inconnu.');
        }

        $points = $app->points->published($id, $bbox, $app->config['max_points'], $filters, $present);
        Http::features((function () use ($points, $collection, $feature) {
            foreach ($points as $point) {
                yield $feature($collection, $point);
            }
        })());
    });

    $router->add('GET', '/api/collections/{id}/points/{pointId}', function (string $id, string $pointId) use ($app, $feature, $published) {
        $collection = $app->collections->get($id);
        Http::json($feature($collection, $published($id, $pointId)));
    });

    $router->add('POST', '/api/collections/{id}/points', function (string $id) use ($app, $rateLimit) {
        $collection = $app->collections->get($id);
        if (!$collection['public_submission']) {
            throw new HttpError(403, 'Cette collection n\'accepte pas de soumissions publiques.');
        }
        [$data, $files] = Http::input();
        $status = $collection['moderation'] ? 'pending' : 'published';

        // Champ piège invisible pour les humains : un robot le remplit, on fait semblant d'accepter.
        if (!empty($data['website'])) {
            Http::json(['status' => $status], 201);
            return;
        }

        $rateLimit($files);
        $position = Points::position($data);
        $properties = $app->collections->validate($collection, (array) ($data['properties'] ?? []), $files);
        $app->points->create($id, $position, $properties, $status);
        Http::json(['status' => $status], 201);
    });

    // Proposition de modification d'un point publié : seuls les champs envoyés changent.
    $router->add('POST', '/api/collections/{id}/points/{pointId}', function (string $id, string $pointId) use ($app, $published, $rateLimit) {
        $collection = $app->collections->get($id);
        if (!$collection['public_edit']) {
            throw new HttpError(403, 'Cette collection n\'accepte pas de propositions de modification.');
        }
        $point = $published($id, $pointId);
        [$data, $files] = Http::input();
        $status = $collection['moderation'] ? 'pending' : 'published';

        if (!empty($data['website'])) {
            Http::json(['status' => $status], 201);
            return;
        }

        $rateLimit($files);
        $position = isset($data['lat']) || isset($data['lng']) ? Points::position($data) : null;
        if ($position === [$point['lat'], $point['lng']]) {
            $position = null;
        }
        $comment = Edits::comment($data['comment'] ?? null);
        $changes = $app->edits->changes($collection, $point, (array) ($data['properties'] ?? []), $files);
        if (!$changes && !$position) {
            throw new HttpError(422, 'Aucune modification.');
        }

        if ($status === 'published') {
            $app->edits->applyTo($point, $position, $changes);
        } else {
            $app->edits->create($point['id'], $position, $changes, $comment);
        }
        Http::json(['status' => $status], 201);
    });

    $router->add('GET', '/api/files/{name}', function (string $name) use ($app) {
        $app->images->send($name);
    });
};
