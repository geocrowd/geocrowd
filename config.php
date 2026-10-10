<?php

// Configuration de l'instance. Les valeurs ci-dessous conviennent à la plupart des hébergements.
return [
    // Fichier SQLite (créé automatiquement) et dossier des images et de la clé de chiffrement
    // (secret.php, à sauvegarder avec la base). Le .htaccess les protège ; sur un hébergement
    // qui le permet, mieux vaut les placer hors de la racine web, par exemple :
    // 'database' => dirname(__DIR__) . '/geocrowd-data/geocrowd.sqlite',
    // 'data_dir' => dirname(__DIR__) . '/geocrowd-data',
    'database' => __DIR__ . '/data/geocrowd.sqlite',
    'data_dir' => __DIR__ . '/data',

    // Origines autorisées à appeler l'API publique ('*' = toutes).
    'cors_origin' => '*',

    // true : l'API publique n'accepte que les requêtes portant une clé, créée dans le back-office
    // (page « Clés d'API ») et éventuellement limitée à certains domaines.
    'api_key' => false,

    // Envois publics (soumissions et propositions de modification) : maximum par adresse IP
    // (par bloc /64 en IPv6) et par heure, puis pour l'ensemble des visiteurs et par heure.
    'submissions_per_hour' => 20,
    'submissions_total_per_hour' => 500,

    // Au-delà de ce nombre de points et de propositions en attente, les envois publics sont refusés.
    'max_pending' => 2000,

    // Place maximale des images, en Mo : au-delà, les envois publics avec images sont refusés.
    'uploads_max_mb' => 2000,

    // Lectures de listes de points par adresse IP et par minute.
    'reads_per_minute' => 120,

    // Envoi d'e-mails (double authentification par e-mail). null : désactivé.
    // Avec seulement 'from', la fonction mail() de PHP est utilisée ; avec 'smtp', un serveur SMTP.
    // 'mail' => [
    //     'from' => 'geocrowd@exemple.org',
    //     'from_name' => 'geocrowd',
    //     'smtp' => ['host' => 'mail.infomaniak.com', 'port' => 587, 'secure' => 'tls',
    //                'user' => 'geocrowd@exemple.org', 'password' => '…'],
    // ],
    'mail' => null,

    // Durée de validité d'un lien d'invitation, en jours.
    'invitation_days' => 7,

    // Nombre maximum de points renvoyés par une requête de lecture.
    'max_points' => 100000,
];
