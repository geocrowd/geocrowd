<?php

/**
 * Import de points OpenStreetMap dans une collection, décrit par un fichier JSON (voir le README).
 *
 *   php bin/import-osm.php bancs.json              interroge Overpass et importe
 *   php bin/import-osm.php bancs.json --file=x.json importe une réponse Overpass enregistrée
 *   php bin/import-osm.php bancs.json --dry-run     compte sans rien écrire
 *
 * Relancé, il met à jour les points déjà importés (propriétés OSM et position) sans toucher
 * à leur statut ni aux autres propriétés. Les points saisis à la main ne sont jamais modifiés.
 * Les données OSM sont sous licence ODbL : voir le README.
 */

namespace Geocrowd;

const OVERPASS = 'https://overpass-api.de/api/interpreter';

PHP_SAPI === 'cli' || exit("À lancer en ligne de commande.\n");

spl_autoload_register(fn($class) => require dirname(__DIR__) . '/src/' . substr($class, strlen(__NAMESPACE__) + 1) . '.php');

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$file = substr((string) current(preg_grep('/^--file=/', $args)), 7);
$path = current(preg_grep('/^[^-]/', $args));
$definition = ($path && is_file($path) ? json_decode(file_get_contents($path), true) : null)
    ?? exit("Usage : php bin/import-osm.php <import>.json [--file=reponse.json] [--dry-run]\n");

$response = $file ? file_get_contents($file) : overpass($definition);
$elements = json_decode($response, true)['elements'] ?? exit("Réponse Overpass illisible.\n");

$app = new App(require dirname(__DIR__) . '/config.php', dirname(__DIR__));
$collection = $app->collections->get($definition['collection'])['id'];
$existing = $app->points->imported($collection);
$created = $updated = 0;

$app->pdo->beginTransaction();
foreach ($elements as $element) {
    $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
    $lng = $element['lon'] ?? $element['center']['lon'] ?? null;
    if ($lat === null || $lng === null) {
        continue;
    }
    $source = "osm:{$element['type']}/{$element['id']}";
    $properties = properties($definition['fields'], $element['tags'] ?? []);

    if ($point = $existing[$source] ?? null) {
        $updated++;
        $dryRun || $app->points->update($point['id'], [$lat, $lng], array_merge($point['properties'], $properties), $point['status']);
    } else {
        $created++;
        $dryRun || $app->points->create($collection, [$lat, $lng], $properties, 'published', $source);
    }
}
$app->pdo->commit();

echo count($elements) . " objets OSM : $created créés, $updated mis à jour" . ($dryRun ? ' (simulation)' : '') . ".\n";

/** Requête Overpass sur la zone (code ISO 3166-1) de la définition. */
function overpass(array $definition): string
{
    $query = "[out:json][timeout:900];area[\"ISO3166-1\"=\"{$definition['area']}\"][admin_level=2]->.zone;({$definition['query']});out center tags;";
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: geocrowd-import\r\n",
        'content' => http_build_query(['data' => $query]),
        'timeout' => 900,
    ]]);
    return @file_get_contents(OVERPASS, false, $context) ?: exit("Overpass ne répond pas. Réessayez plus tard.\n");
}

/**
 * Propriétés du point : valeur fixe, ou tag OSM traduit par « map », sinon « default ».
 * « angle » : angle en degrés, traduit par « map » (points cardinaux) ou lu tel quel
 * s'il est numérique, arrondi et ramené entre 0 et 359 ; ignoré sinon (« both », « 0-359 »).
 */
function properties(array $fields, array $tags): array
{
    $properties = [];
    foreach ($fields as $name => $field) {
        $tag = isset($field['tag']) ? ($tags[$field['tag']] ?? null) : null;
        if (!empty($field['angle'])) {
            $raw = $field['map'][$tag] ?? $tag;
            $value = is_numeric($raw) ? ((int) round((float) $raw) % 360 + 360) % 360 : null;
        } else {
            $value = $field['value'] ?? (isset($field['map']) ? ($field['map'][$tag] ?? null) : $tag) ?? $field['default'] ?? null;
        }
        if ($value !== null) {
            $properties[$name] = $value;
        }
    }
    return $properties;
}
