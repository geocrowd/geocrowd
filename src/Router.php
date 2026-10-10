<?php

namespace Geocrowd;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        $allowed = false;
        foreach ($this->routes as [$routeMethod, $regex, $handler]) {
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }
            if ($routeMethod !== $method) {
                $allowed = true;
                continue;
            }
            $handler(...array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
            return;
        }
        throw $allowed ? new HttpError(405, 'Méthode non autorisée.') : new HttpError(404, 'Ressource introuvable.');
    }
}
