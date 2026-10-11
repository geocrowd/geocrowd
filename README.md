# geocrowd

Collecte et publication d'informations géolocalisées : une API publique en GeoJSON pour lire et envoyer des points, un back-office pour les modérer, et un site public facultatif.

PHP 8.1+ (extensions pdo_sqlite, gd, openssl) et SQLite. Rien à installer d'autre.

Documentation : https://geocrowd-api.org

## Installation

1. Copier les fichiers sur l'hébergement.
2. Rendre `data/` accessible en écriture.
3. Ouvrir `/admin/` et créer le premier compte.

Les réglages sont dans `config.php`. En local : `php -S localhost:8000 index.php`.

## Développement

Le CSS est compilé avec Tailwind puis commité : `npm install`, puis `npm run build:css`.

## Licence

MIT. Pour signaler une faille, voir [SECURITY.md](SECURITY.md).
