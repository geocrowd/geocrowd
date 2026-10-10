<?php

namespace Geocrowd;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serveur PHP intégré (développement) : sert directement les fichiers du back-office, du site public et des bibliothèques.
if (PHP_SAPI === 'cli-server' && preg_match('#^/(admin|front|vendor)/#', $path) && !str_starts_with($path, '/admin/api/')) {
    return false;
}

spl_autoload_register(function (string $class) {
    $file = __DIR__ . '/src/' . substr($class, strlen(__NAMESPACE__) + 1) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Http::securityHeaders();

// Chemin de l'application, pour une installation dans un sous-dossier.
$base = rtrim(str_replace('\\', '/', substr(__DIR__, strlen(realpath($_SERVER['DOCUMENT_ROOT'])))), '/');
$route = substr($path, strlen($base)) ?: '/';

if ($route === '/admin') {
    header('Location: ' . $base . '/admin/');
    exit;
}

try {
    $app = new App(require __DIR__ . '/config.php', __DIR__);
    $router = new Router();
    // Ici et pas dans App : un script lancé en local sur une copie de la base n'a pas la clé du serveur.
    $app->users->encryptLegacySecrets();

    // Racine : le site public s'il est activé, sinon le back-office.
    if ($route === '/') {
        if (!$app->front->enabled()) {
            header('Location: ' . $base . '/admin/');
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo str_replace('<base href="/">', '<base href="' . htmlspecialchars($base . '/') . '">', file_get_contents(__DIR__ . '/front/index.html'));
        exit;
    }

    if (str_starts_with($route, '/api/')) {
        // Clé exigée (sauf pour les images, dont les noms ne se devinent pas).
        $keyRequired = ($app->config['api_key'] ?? false) && !str_starts_with($route, '/api/files/');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $originAllowed = $keyRequired && $origin && $app->apiKeys->allowsOrigin($origin);

        header('Access-Control-Allow-Origin: ' . ($originAllowed ? $origin : $app->config['cors_origin']));
        header('Access-Control-Allow-Methods: GET, POST');
        header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');
        header('Vary: Origin');
        if (Http::method() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        // Pages du site public de l'instance (même domaine) : acceptées sans clé tant qu'il est activé.
        $source = $origin ?? $_SERVER['HTTP_REFERER'] ?? null;
        $sameSite = $source && parse_url($source, PHP_URL_HOST) === parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if ($keyRequired && !($sameSite && $app->front->enabled())) {
            $app->apiKeys->authorize($_SERVER['HTTP_X_API_KEY'] ?? Http::query('key'), $source);
        }
        (require __DIR__ . '/src/routes/public.php')($router, $app, $base);
    } elseif (str_starts_with($route, '/admin/api/')) {
        $auth = new Auth($app->users);
        if (Http::method() !== 'GET') {
            $auth->checkCsrf();
        }
        (require __DIR__ . '/src/routes/admin.php')($router, $app, $auth);
    }

    $router->dispatch(Http::method(), $route);
} catch (HttpError $e) {
    Http::error($e);
} catch (\Throwable $e) {
    error_log((string) $e);
    Http::error(new HttpError(500, 'Erreur interne du serveur.'));
}
