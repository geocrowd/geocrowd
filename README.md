# geocrowd

Application open source pour recueillir des points géolocalisés : une API publique pour les lire, en soumettre et proposer des corrections, et un back-office pour les modérer, les ajouter et gérer les membres.

Site de présentation et documentation complète : dépôt séparé [geocrowd-site](https://github.com/geocrowd/geocrowd-site).

Chaque usage (bancs publics, arbres remarquables, points d'eau…) est une **collection**, créée dans l'éditeur de schéma du back-office : ses champs, leur validation et ses règles de modération.

## Installation

Prérequis : PHP 8.1 ou plus avec les extensions `pdo_sqlite`, `gd` et `openssl` (présentes sur la plupart des hébergements mutualisés).

1. Copier les fichiers sur l'hébergement (FTP ou autre).
2. Vérifier que le dossier `data/` est accessible en écriture par PHP.
3. Ouvrir `https://votre-site/admin/`, créer le premier compte admin, puis une première collection dans la page « Collections ».

Sur Apache, le fichier `.htaccess` fourni protège les dossiers internes (`src/`, `data/`, `bin/`, ainsi que `collections/` et `imports/` d'anciennes versions), `config.php` et les fichiers cachés (`.git`, `.env`…), et ajoute les en-têtes de sécurité aux fichiers statiques. Sur Nginx, il faut reproduire ces règles (voir la documentation) et rediriger les requêtes vers `index.php`. Si la base reste téléchargeable, le back-office l'indique aux comptes admin par une alerte.

En local : `php -S localhost:8000 index.php`, puis ouvrir http://localhost:8000/admin/.

Les réglages (limite de soumissions, origine CORS, clés d'API, durée des invitations…) sont dans `config.php`.

L'horloge du serveur doit être à l'heure : les codes de double authentification en dépendent.

## Collections

Les collections se créent et se modifient dans le back-office (page « Collections », comptes admin) : nom, identifiant, règles d'envoi et champs, avec un aperçu de la définition JSON que l'API renvoie :

```json
{
  "id": "bancs",
  "name": "Bancs publics",
  "description": "Bancs de la ville et leur état.",
  "moderation": true,
  "public_submission": true,
  "public_edit": true,
  "fields": [
    { "name": "etat", "label": "État", "type": "select", "required": true, "options": ["Bon", "Abîmé", "Cassé"] },
    { "name": "places", "label": "Places assises", "type": "number", "min": 1, "max": 20 },
    { "name": "dossier", "label": "Avec dossier", "type": "boolean" },
    { "name": "photos", "label": "Photos", "type": "image", "max": 3, "maxSize": 5 }
  ]
}
```

- `id` : identifiant utilisé dans les adresses de l'API ; il ne change plus après la création.
- `description` : texte facultatif (1000 caractères), affiché sur le site public.
- `moderation` : si `true`, les soumissions publiques attendent une validation ; sinon elles sont publiées directement.
- `public_submission` : autorise ou non les soumissions par l'API publique.
- `public_edit` : autorise ou non les propositions de modification par l'API publique (par défaut, comme `public_submission`). Elles suivent la même règle de modération.

Chaque point a toujours une latitude et une longitude. Types de champs disponibles :

| Type | Options |
|---|---|
| `text`, `textarea`, `url` | `required`, `maxLength` |
| `number` | `required`, `min`, `max` |
| `select` | `required`, `options` |
| `multiselect` | `required`, `options` : la valeur est une liste de choix |
| `boolean` | |
| `date` | `required` : la valeur est au format `AAAA-MM-JJ` |
| `image` | `required`, `max` (nombre d'images, 1 par défaut), `maxSize` (Mo, 5 par défaut) |
| `ref` | `required`, `collection` : la valeur est le numéro d'un point publié de cette collection |

Un champ `ref` relie un point à un autre, par exemple un signalement au banc concerné.

Le nom technique (`name`, clé des données) et le type d'un champ enregistré ne changent plus ; son libellé, ses options et sa place, si. Retirer un champ efface ses valeurs de tous les points de la collection, images comprises (le back-office indique combien de points sont concernés et demande confirmation). Supprimer une collection supprime ses points ; c'est impossible tant qu'un champ `ref` d'une autre collection la désigne.

Les collections sont enregistrées dans la base SQLite. Une installation antérieure qui les décrivait dans des fichiers `collections/<id>.json` les importe automatiquement, une seule fois, au premier lancement.

Les images (JPEG, PNG, WebP) sont réencodées à l'enregistrement : leurs métadonnées, dont la géolocalisation de l'appareil photo, sont supprimées, et leur plus grand côté est limité à 2000 px.

## API publique

| Méthode | Chemin | Rôle |
|---|---|---|
| `GET` | `/api/collections` | Liste des collections, de leurs champs et de leur nombre de points publiés |
| `GET` | `/api/collections/{id}` | Une collection |
| `GET` | `/api/collections/{id}/points?bbox=ouest,sud,est,nord` | Points publiés, en GeoJSON |
| `GET` | `/api/collections/{id}/points?{champ}={n}` | Points publiés liés au point `n` par un champ `ref` |
| `GET` | `/api/collections/{id}/points?with={champ}` | Points publiés dont le champ est renseigné |
| `GET` | `/api/collections/{id}/points/{n}` | Un point publié, en GeoJSON |
| `POST` | `/api/collections/{id}/points` | Soumettre un point |
| `POST` | `/api/collections/{id}/points/{n}` | Proposer une modification d'un point publié |
| `GET` | `/api/files/{nom}` | Image d'un point |
| `GET` | `/api/front` | Configuration du site public (`404` s'il est désactivé) |

Soumission sans image, en JSON :

```json
{ "lat": 45.764, "lng": 4.8357, "properties": { "type": "Dôme" } }
```

Avec des images, en `multipart/form-data` : le JSON ci-dessus dans le champ `data`, et les fichiers dans des champs nommés comme la propriété (`photos[]`).

Les erreurs de validation renvoient un statut `422` avec le détail par champ :

```json
{ "error": "Certains champs sont invalides.", "details": { "type": "Champ obligatoire." } }
```

Une proposition de modification a le même format, mais tout y est facultatif : `lat` et `lng` (ensemble), `properties` avec **seulement les champs modifiés** (`null` pour vider un champ), et `comment` pour expliquer la correction à l'équipe de modération (1000 caractères). Pour un champ image, la valeur liste les images actuelles à garder (URL renvoyées par l'API) ; les fichiers envoyés s'y ajoutent.

```json
{ "properties": { "etat": "Cassé" }, "comment": "Assise fendue depuis mars." }
```

La réponse est `{"status": "pending"}`, ou `{"status": "published"}` si la collection n'est pas modérée (la modification est alors appliquée tout de suite). Dans le back-office, la page « Modifications » montre l'avant et l'après de chaque champ ; seuls les champs proposés sont modifiés à l'application.

Les envois publics (soumissions et propositions) sont limités, avec des valeurs réglables dans `config.php` :

- 20 par heure et par adresse IP (par bloc /64 en IPv6, qu'une seule connexion peut parcourir), et 500 par heure pour l'ensemble des visiteurs (`429` au-delà) ;
- refusés quand 2000 points et propositions attendent déjà la modération (`429`) ;
- refusés avec images quand celles-ci occupent déjà 2000 Mo (`507`).

Les lectures de listes de points sont limitées à 120 par minute et par adresse IP. Les adresses ne sont stockées que sous forme de hachage. Un champ `website` rempli est traité comme un robot : la réponse est un succès, mais rien n'est enregistré.

### Clés d'API

Avec `'api_key' => true` dans `config.php`, l'API n'accepte que les requêtes portant une clé, dans l'en-tête `X-Api-Key` ou le paramètre `?key=` (les images de `/api/files/` restent accessibles sans clé : leurs noms ne se devinent pas). Les clés se créent dans le back-office, page « Clés d'API » (comptes admin) :

- **Clé limitée à des domaines** (`exemple.org`, `*.exemple.org`) : acceptée seulement depuis les pages de ces sites, d'après les en-têtes `Origin` ou `Referer` envoyés par le navigateur. Elle peut figurer dans le code JavaScript d'une carte publique. Un script hors navigateur peut imiter ces en-têtes : la limite empêche la réutilisation de la clé par d'autres sites, pas par un robot déterminé.
- **Clé sans domaine** : utilisable de partout (serveur, scripts). Elle doit rester secrète.

Sans clé valide : `401`. Domaine non autorisé : `403`. La clé n'est affichée qu'à sa création ; le back-office en garde un hachage et la date de dernière utilisation.

## Site public

Un site public facultatif peut être servi à la racine de l'instance (`https://votre-instance.org/`) : une carte des points publiés, la fiche de chaque point (avec les points liés), un formulaire d'ajout généré à partir du schéma de la collection et un formulaire de proposition de modification. Il s'adresse aux projets qui n'ont pas de site pour consommer l'API.

Il se règle dans le back-office, page « Site public » (comptes admin) : activation, titre, texte d'introduction, collections affichées, ajouts et propositions de modification permis, vue de carte par défaut. Les envois suivent les règles de chaque collection et les limites de l'API. Désactivé (par défaut), la racine renvoie vers le back-office.

Quand `api_key` est activé, les requêtes envoyées depuis les pages de l'instance elle-même (même domaine) sont acceptées sans clé tant que le site public est activé.

## Back-office

Deux rôles :

- **Modération** : valide, refuse, ajoute, modifie et supprime des points.
- **Admin** : tout, plus la gestion des membres et la consultation des collections.

Pour inviter une personne, un compte admin crée un lien d'invitation (valable 7 jours) et le lui transmet ; elle y choisit son mot de passe.

### Double authentification

Chaque membre peut protéger son compte par une double authentification, depuis la page « Mon compte » : après le mot de passe, la connexion demande un code à 6 chiffres, obtenu de deux façons au choix.

- **Application d'authentification** (TOTP : Aegis, FreeOTP, Google Authenticator, 1Password, Bitwarden…) : le code est calculé à partir d'un secret partagé et de l'heure ; le serveur n'envoie rien. C'est la méthode recommandée. L'horloge du serveur doit être à l'heure.
- **Code par e-mail** : un code valable 10 minutes est envoyé à l'adresse du compte (un envoi par minute au plus et 5 par heure, par compte). Disponible seulement si l'envoi d'e-mails est configuré (clé `mail` de `config.php`). Moins sûre : elle vaut ce que vaut la sécurité de la boîte mail.

La règle de l'instance se choisit dans la page « Membres » (comptes admin) : **facultative** (par défaut, chaque membre décide), **obligatoire pour les comptes admin**, ou **obligatoire pour tous**. Un membre concerné qui n'en a pas la configure à sa connexion suivante ; il ne peut pas la désactiver. Un compte admin ne peut pas imposer une règle qu'il ne respecte pas lui-même.

À l'activation, dix codes de secours à usage unique sont affichés une seule fois. « Mon compte » permet de changer de méthode ou d'application (nouveau téléphone), de générer de nouveaux codes de secours et de désactiver la double authentification ; chacune de ces actions redemande le mot de passe, pour qu'une session détournée ne puisse pas remplacer le second facteur. Second facteur perdu :

1. se connecter avec un code de secours ;
2. sinon, un autre compte admin réinitialise la double authentification depuis la page « Membres » ;
3. en dernier recours, avec accès aux fichiers : `php bin/reset-2fa.php adresse@exemple.org`. Sans accès SSH : télécharger par FTP `data/geocrowd.sqlite` et, s'ils existent, `geocrowd.sqlite-wal` et `geocrowd.sqlite-shm` ; lancer le script en local avec `--db=geocrowd.sqlite` ; renvoyer `geocrowd.sqlite` et supprimer les fichiers `-wal` et `-shm` du serveur.

Les essais sont limités : 10 mots de passe faux par adresse IP (bloc /64 en IPv6) et 5 codes faux par compte en 15 minutes. Un code déjà utilisé est refusé.

Les secrets des applications d'authentification sont chiffrés en base (AES-256-GCM, extension `openssl`) avec une clé gardée dans `secret.php` du dossier de données, créée au premier besoin : une base copiée seule ne permet pas de générer les codes. Ce fichier se sauvegarde avec la base ; s'il est perdu, les codes de secours restent utilisables, puis chaque membre concerné reconfigure sa double authentification.

### Envoi d'e-mails

Désactivé par défaut (`'mail' => null`). Avec seulement l'expéditeur, geocrowd utilise la fonction `mail()` de PHP ; avec un serveur SMTP (STARTTLS sur le port 587, ou TLS direct sur le 465), il s'y connecte lui-même :

```php
'mail' => [
    'from' => 'geocrowd@exemple.org',
    'smtp' => ['host' => 'mail.infomaniak.com', 'port' => 587, 'secure' => 'tls',
               'user' => 'geocrowd@exemple.org', 'password' => '…'],
],
```

`secure` vaut `tls` (STARTTLS) par défaut, `ssl` pour TLS direct ; toute autre valeur ouvre une connexion **en clair**, identifiants compris, à réserver à un serveur local. `from_name` (nom de l'expéditeur) vaut `geocrowd` par défaut. L'envoi n'est actif que si `from` est une adresse valide. La page « Membres » indique le mode d'envoi et permet d'envoyer un e-mail de test ; en cas d'échec, le détail est écrit dans le journal d'erreurs de PHP.

## Import OpenStreetMap

`bin/import-osm.php` importe des objets OpenStreetMap comme points publiés d'une collection. Un fichier JSON décrit la collection cible, la zone (code ISO 3166-1), la requête Overpass et la correspondance des champs : valeur fixe (`value`), tag OSM (`tag`), traduit par `map`, avec une valeur par défaut (`default`). Avec `angle`, le tag est un angle en degrés (traduit par `map` ou numérique), ramené entre 0 et 359. Par exemple, `bancs.json` :

```json
{
  "collection": "bancs",
  "area": "FR",
  "query": "node[amenity=bench](area.zone);",
  "fields": {
    "etat": { "value": "Bon" },
    "dossier": { "tag": "backrest", "map": { "yes": true, "no": false } }
  }
}
```

```bash
php bin/import-osm.php bancs.json --dry-run   # compte sans rien écrire
php bin/import-osm.php bancs.json             # importe
```

Les valeurs importées ne passent pas par la validation de la collection : vérifiez qu'elles font partie des choix des champs à choix.

Relancé, il met à jour les points déjà importés (position et champs issus d'OSM) sans changer leur statut ni leurs autres champs : un point refusé reste refusé. Les points saisis à la main ne sont jamais modifiés. `--file=reponse.json` importe une réponse Overpass enregistrée, si le serveur ne peut pas joindre Overpass.

Les données OpenStreetMap sont sous licence [ODbL](https://opendatacommons.org/licenses/odbl/) : citez « © les contributrices et contributeurs OpenStreetMap » là où les points sont affichés, et diffusez la base qui les contient sous la même licence.

## Développement

L'interface du back-office et du site public utilise [Tailwind CSS](https://tailwindcss.com) : les classes sont écrites dans `admin/index.html`, `admin/js/`, `front/index.html` et `front/front.js`, le thème et les composants répétés dans `styles/admin.css` et `styles/front.css`. Le CSS compilé (`admin/css/admin.css`, `front/front.css`) est commité : une installation n'a besoin ni de Node ni d'aucune étape de construction.

Après une modification de l'interface :

```bash
npm install          # une fois
npm run build:css    # recompile admin/css/admin.css et front/front.css
npm run watch:admin  # ou watch:front : recompilation à chaque enregistrement
```

## Sécurité

- **Comptes** : mots de passe hachés (bcrypt), double authentification par application ou par e-mail (facultative ou imposée selon la règle de l'instance), essais limités, session en cookie `HttpOnly`, `SameSite=Strict` et `Secure` en HTTPS, jeton anti-CSRF sur toute action.
- **Journal** : connexions (réussies et refusées) et actions du back-office (modération, schéma, membres, clés, réglages) sont consignées un an, page « Journal » (comptes admin).
- **Contenu envoyé par le public** : affiché comme du texte, jamais comme du HTML ; adresses web limitées à `http` et `https` ; images réencodées, sans métadonnées, servies avec `nosniff`.
- **En-têtes** : politique de sécurité du contenu stricte (scripts, styles et polices de l'instance uniquement, fonds de carte et recherche de lieux OpenStreetMap), interdiction d'affichage dans un cadre, `Referrer-Policy`, HSTS en HTTPS.
- **Aucune ressource tierce** hormis OpenStreetMap : Leaflet, le générateur de QR code et les polices sont fournis dans `vendor/`.
- **Données** : sur un hébergement qui le permet, placer la base et le dossier de données hors de la racine web (`database` et `data_dir` dans `config.php`). Sauvegarder ensemble `geocrowd.sqlite`, `secret.php` et `uploads/`.
- Signaler une faille : en privé, par l'onglet « Security » du dépôt GitHub.

## Licence

MIT, voir [LICENSE](LICENSE).
