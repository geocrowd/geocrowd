<?php

/**
 * Réinitialise la double authentification d'un membre qui a perdu son téléphone et ses
 * codes de secours, quand aucun autre compte admin ne peut le faire depuis le back-office.
 * Ses codes de secours sont supprimés ; si la règle de l'instance l'exige, il la reconfigure à sa
 * prochaine connexion, après son mot de passe.
 *
 *   php bin/reset-2fa.php adresse@exemple.org
 *   php bin/reset-2fa.php adresse@exemple.org --db=geocrowd.sqlite   sur une copie de la base
 *
 * Sans accès SSH à l'hébergement : télécharger par FTP data/geocrowd.sqlite et, s'ils existent,
 * geocrowd.sqlite-wal et geocrowd.sqlite-shm (écritures récentes) ; lancer le script en local
 * avec --db ; renvoyer geocrowd.sqlite et supprimer les fichiers -wal et -shm du serveur.
 */

namespace Geocrowd;

PHP_SAPI === 'cli' || exit("À lancer en ligne de commande.\n");

spl_autoload_register(fn($class) => require dirname(__DIR__) . '/src/' . substr($class, strlen(__NAMESPACE__) + 1) . '.php');

$args = array_slice($argv, 1);
$db = substr((string) current(preg_grep('/^--db=/', $args)), 5);
$email = current(preg_grep('/^[^-]/', $args))
    ?: exit("Usage : php bin/reset-2fa.php adresse@exemple.org [--db=chemin/vers/geocrowd.sqlite]\n");

$config = require dirname(__DIR__) . '/config.php';
if ($db) {
    is_file($db) || exit("Base introuvable : $db\n");
    $config['database'] = $db;
}

$app = new App($config, dirname(__DIR__));
$user = $app->users->findByEmail($email) ?? exit("Aucun membre avec l'adresse $email.\n");
$app->users->resetTwoFactor($user['id']);
echo "Double authentification réinitialisée pour {$user['name']} ($email).\n";
