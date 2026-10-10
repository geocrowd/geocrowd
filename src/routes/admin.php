<?php

namespace Geocrowd;

/** API du back-office. Toute requête autre que GET doit porter le jeton CSRF. */
return static function (Router $router, App $app, Auth $auth): void {
    // Adresse partiellement masquée, pour indiquer où le code a été envoyé.
    $maskEmail = fn (string $email) => mb_substr($email, 0, 1) . '…' . strstr($email, '@');

    // twoFactor : 'enroll' (à configurer) ou 'verify' (code attendu) après la vérification du mot de passe.
    $session = function (array $extra = []) use ($app, $auth, $maskEmail) {
        $user = $auth->user();
        $pending = $user ? null : $auth->pending();
        Http::json([
            'user' => $user,
            'twoFactor' => $pending ? ($pending['two_factor'] ? 'verify' : 'enroll') : null,
            'twoFactorMethod' => $pending['two_factor_method'] ?? null,
            'emailHint' => $pending ? $maskEmail($pending['email']) : null,
            'mailEnabled' => $app->mailer->enabled(),
            'csrf' => $auth->csrf(),
            'needsSetup' => $app->users->count() === 0,
        ] + $extra);
    };

    $log = fn (?array $user, string $action, string $target = '', array $details = []) => $app->audit->log($user, $action, $target, $details);

    // Essais de mot de passe par adresse IP, de codes par membre, envois de codes par e-mail par membre.
    $passwordLimit = new RateLimit($app->pdo, 'login', 10, 900);
    $codeLimit = new RateLimit($app->pdo, 'code', 5, 900);
    $emailLimit = new RateLimit($app->pdo, 'email-code', 5, 3600);
    $resendLimit = new RateLimit($app->pdo, 'email-code-resend', 1, 60);
    $tooMany = 'Trop d\'essais, réessayez dans quelques minutes.';

    // Membre dont le mot de passe vient d'être vérifié, et qui doit saisir son code.
    $verifying = function () use ($auth) {
        $user = $auth->pending() ?? throw new HttpError(401, 'Reconnectez-vous.');
        return $user['two_factor'] ? $user : throw new HttpError(409, 'Étape de connexion inattendue, reconnectez-vous.');
    };

    // Membre qui configure sa double authentification : à la connexion (étape en attente, mot de passe
    // tout juste vérifié) ou depuis « Mon compte », après avoir confirmé son mot de passe : une session
    // détournée ne peut pas remplacer le second facteur.
    $enrolling = function () use ($auth) {
        if ($user = $auth->user()) {
            return $auth->recentlyConfirmed() ? $user : throw new HttpError(403, 'Confirmez d\'abord votre mot de passe.');
        }
        $user = $auth->pending() ?? throw new HttpError(401, 'Reconnectez-vous.');
        return $user['two_factor'] ? throw new HttpError(409, 'Étape de connexion inattendue, reconnectez-vous.') : $user;
    };

    // Envoie un code par e-mail ; le compteur n'avance que si l'envoi a réussi.
    $sendCode = function (array $user, string $purpose) use ($app, $auth, $emailLimit, $resendLimit) {
        $resendLimit->check((string) $user['id'], 'Un code vient d\'être envoyé : utilisez-le, ou attendez une minute avant d\'en demander un autre.');
        $emailLimit->check((string) $user['id'], 'Trop de codes envoyés, réessayez dans une heure.');
        $code = $auth->newEmailCode($user, $purpose);
        try {
            $app->mailer->send($user['email'], 'Votre code de connexion geocrowd',
                "Bonjour,\n\nVotre code pour le back-office geocrowd est : $code\n\nIl est valable 10 minutes. "
                . "Si vous n'êtes pas à l'origine de cette demande, changez votre mot de passe.\n");
        } catch (HttpError $e) {
            $auth->cancelEmailCode();
            throw $e;
        }
        $emailLimit->record((string) $user['id']);
        $resendLimit->record((string) $user['id']);
    };

    // Après le mot de passe : code à saisir, double authentification à configurer, ou connexion directe.
    $afterPassword = function (array $user) use ($app, $auth, $log, $sendCode) {
        if (!$user['two_factor'] && !$app->users->requiresTwoFactor($user)) {
            $log($user, 'login', $user['email']);
            $auth->login($user);
            return [];
        }
        $auth->passwordChecked($user);
        if ($user['two_factor_method'] === 'email') {
            try {
                $sendCode($user, 'login');
            } catch (HttpError $e) {
                // La personne peut renvoyer le code ou utiliser un code de secours.
                return ['emailError' => $e->getMessage()];
            }
        }
        return [];
    };

    // Fin de configuration : connexion si elle avait lieu pendant la connexion, codes de secours.
    $enabled = function (array $user, string $method, array $codes) use ($auth, $log, $session) {
        $wasPending = !$auth->user();
        $auth->enrolled();
        $log($user, 'two_factor.enabled', $user['email'], ['method' => $method]);
        if ($wasPending) {
            $log($user, 'login', $user['email']);
            $auth->login($user);
        }
        $session(['recoveryCodes' => $codes]);
    };

    // Session et comptes

    $router->add('GET', '/admin/api/session', fn () => $session());

    $router->add('POST', '/admin/api/setup', function () use ($app, $session, $log, $afterPassword) {
        if ($app->users->count() > 0) {
            throw new HttpError(403, 'L\'installation est déjà faite.');
        }
        $data = Http::body();
        $id = $app->users->create($data['email'] ?? '', $data['name'] ?? '', $data['password'] ?? '', 'admin');
        $user = $app->users->find($id);
        $log($user, 'account.setup', $user['email']);
        $session($afterPassword($user));
    });

    $router->add('POST', '/admin/api/login', function () use ($app, $session, $passwordLimit, $tooMany, $log, $afterPassword) {
        $passwordLimit->check(Http::clientKey(), $tooMany);
        $data = Http::body();
        $user = $app->users->authenticate((string) ($data['email'] ?? ''), (string) ($data['password'] ?? ''));
        if (!$user) {
            $passwordLimit->record(Http::clientKey());
            $log(null, 'login.failed', trim((string) ($data['email'] ?? '')));
            throw new HttpError(401, 'E-mail ou mot de passe incorrect.');
        }
        $session($afterPassword($user));
    });

    // Application d'authentification : secret à scanner, puis premier code.
    $router->add('GET', '/admin/api/two-factor/setup', function () use ($auth, $enrolling) {
        $user = $enrolling();
        $secret = $auth->enrollmentSecret();
        Http::json(['secret' => $secret, 'uri' => Totp::uri($secret, $user['email'], 'geocrowd')]);
    });

    $router->add('POST', '/admin/api/two-factor/setup', function () use ($app, $auth, $enrolling, $enabled, $codeLimit, $tooMany) {
        $user = $enrolling();
        $codeLimit->check((string) $user['id'], $tooMany);
        $secret = $auth->enrollmentSecret();
        $step = Totp::verify($secret, preg_replace('/\s/', '', (string) (Http::body()['code'] ?? '')));
        if ($step === null) {
            $codeLimit->record((string) $user['id']);
            throw new HttpError(422, 'Code incorrect.', ['code' => 'Vérifiez l\'heure de votre téléphone et saisissez le code affiché.']);
        }
        $enabled($user, 'totp', $app->users->enableTwoFactor($user['id'], $secret, $step));
    });

    // Code par e-mail : envoi (connexion ou activation), puis confirmation de l'activation.
    $router->add('POST', '/admin/api/two-factor/email/send', function () use ($app, $auth, $enrolling, $sendCode, $maskEmail) {
        $pending = $auth->user() ? null : $auth->pending();
        if ($pending && $pending['two_factor_method'] === 'email') {
            $sendCode($pending, 'login');
            $user = $pending;
        } else {
            $user = $enrolling();
            if (!$app->mailer->enabled()) {
                throw new HttpError(503, 'L\'envoi d\'e-mails n\'est pas configuré.');
            }
            $sendCode($user, 'enroll');
        }
        Http::json(['sent' => $maskEmail($user['email'])]);
    });

    $router->add('POST', '/admin/api/two-factor/email/confirm', function () use ($app, $auth, $enrolling, $enabled, $codeLimit, $tooMany) {
        $user = $enrolling();
        $codeLimit->check((string) $user['id'], $tooMany);
        if (!$auth->checkEmailCode($user, (string) (Http::body()['code'] ?? ''), 'enroll')) {
            $codeLimit->record((string) $user['id']);
            throw new HttpError(422, 'Code incorrect.', ['code' => 'Code invalide ou expiré : demandez-en un nouveau.']);
        }
        $enabled($user, 'email', $app->users->enableEmailTwoFactor($user['id']));
    });

    $router->add('POST', '/admin/api/two-factor/verify', function () use ($app, $auth, $session, $verifying, $codeLimit, $tooMany, $log) {
        $user = $verifying();
        $codeLimit->check((string) $user['id'], $tooMany);
        $code = (string) (Http::body()['code'] ?? '');
        $valid = ($user['two_factor_method'] === 'email' && $auth->checkEmailCode($user, $code, 'login'))
            || $app->users->checkCode($user['id'], $code);
        if (!$valid) {
            $codeLimit->record((string) $user['id']);
            $log($user, 'two_factor.failed', $user['email']);
            throw new HttpError(422, 'Code incorrect.', ['code' => $user['two_factor_method'] === 'email'
                ? 'Code invalide ou expiré : demandez-en un nouveau, ou saisissez un code de secours.'
                : 'Code invalide ou déjà utilisé : attendez le suivant, ou saisissez un code de secours.']);
        }
        $log($user, 'login', $user['email']);
        $auth->login($user);
        $session();
    });

    $router->add('POST', '/admin/api/logout', function () use ($auth, $session) {
        $auth->logout();
        $session();
    });

    $router->add('GET', '/admin/api/invitations/{token}', function (string $token) use ($app) {
        $invitation = $app->users->invitation($token);
        Http::json(['email' => $invitation['email'], 'role' => $invitation['role']]);
    });

    $router->add('POST', '/admin/api/invitations/{token}/accept', function (string $token) use ($app, $session, $log, $afterPassword) {
        $data = Http::body();
        $id = $app->users->accept($token, $data['name'] ?? '', $data['password'] ?? '');
        $user = $app->users->find($id);
        $log($user, 'invitation.accepted', $user['email']);
        $session($afterPassword($user));
    });

    // Compte du membre connecté : double authentification et codes de secours.

    // Actions sensibles du compte : mot de passe redemandé.
    $confirmPassword = function (array $user) use ($app, $passwordLimit, $tooMany) {
        $passwordLimit->check(Http::clientKey(), $tooMany);
        if (!$app->users->authenticate($user['email'], (string) (Http::body()['password'] ?? ''))) {
            $passwordLimit->record(Http::clientKey());
            throw new HttpError(422, 'Mot de passe incorrect.', ['password' => 'Mot de passe incorrect.']);
        }
    };

    $router->add('GET', '/admin/api/account', function () use ($app, $auth) {
        $user = $auth->require();
        Http::json([
            'method' => $user['two_factor_method'],
            'required' => $app->users->requiresTwoFactor($user),
            'mailEnabled' => $app->mailer->enabled(),
            'recoveryCodes' => $app->users->recoveryCount($user['id']),
        ]);
    });

    $router->add('POST', '/admin/api/account/confirm-password', function () use ($auth, $confirmPassword) {
        $confirmPassword($auth->require());
        $auth->passwordConfirmed();
        Http::json(['confirmed' => true]);
    });

    $router->add('POST', '/admin/api/account/recovery-codes', function () use ($app, $auth, $confirmPassword, $log) {
        $user = $auth->require();
        if (!$user['two_factor']) {
            throw new HttpError(409, 'Activez d\'abord la double authentification.');
        }
        $confirmPassword($user);
        $log($user, 'recovery_codes.renewed', $user['email']);
        Http::json(['codes' => $app->users->newRecoveryCodes($user['id'])]);
    });

    $router->add('POST', '/admin/api/account/two-factor/disable', function () use ($app, $auth, $confirmPassword, $log) {
        $user = $auth->require();
        if ($app->users->requiresTwoFactor($user)) {
            throw new HttpError(409, 'La double authentification est obligatoire pour votre compte.');
        }
        $confirmPassword($user);
        $app->users->resetTwoFactor($user['id']);
        $log($user, 'two_factor.disabled', $user['email']);
        Http::json(['disabled' => true]);
    });

    // Règle de double authentification et envoi d'e-mails (administrateurs)

    $router->add('GET', '/admin/api/security', function () use ($app, $auth) {
        $auth->require('admin');
        Http::json(['policy' => $app->users->policy(), 'mail' => $app->mailer->transport()]);
    });

    $router->add('PUT', '/admin/api/security', function () use ($app, $auth, $log) {
        $user = $auth->require('admin');
        $policy = Http::body()['policy'] ?? null;
        // Éviter de se fermer la porte : la nouvelle règle ne doit pas exiger ce que le compte n'a pas.
        if (!$user['two_factor'] && in_array($policy, ['admins', 'all'], true)) {
            throw new HttpError(409, 'Activez d\'abord votre propre double authentification (Mon compte).');
        }
        $app->users->setPolicy($policy);
        $log($user, 'security.policy', '', ['policy' => $policy]);
        Http::json(['policy' => $policy, 'mail' => $app->mailer->transport()]);
    });

    $router->add('POST', '/admin/api/security/test-email', function () use ($app, $auth, $log) {
        $user = $auth->require('admin');
        $app->mailer->send($user['email'], 'Test d\'envoi geocrowd', "Bonjour,\n\nCet e-mail de test confirme que geocrowd peut envoyer des e-mails.\n");
        $log($user, 'security.test_email', $user['email']);
        Http::json(['sent' => $user['email']]);
    });

    // Collections et points (modérateurs et administrateurs)

    $router->add('GET', '/admin/api/collections', function () use ($app, $auth) {
        $auth->require();
        Http::json(['collections' => $app->collections->all(), 'counts' => $app->points->counts(), 'edits' => $app->edits->count()]);
    });

    // Éditeur de schéma (administrateurs)

    $router->add('POST', '/admin/api/collections', function () use ($app, $auth, $log) {
        $user = $auth->require('admin');
        $collection = $app->collections->save(Http::body());
        $log($user, 'collection.created', $collection['id']);
        Http::json($collection, 201);
    });

    $router->add('PUT', '/admin/api/collections/{id}', function (string $id) use ($app, $auth, $log) {
        $user = $auth->require('admin');
        $before = array_column($app->collections->get($id)['fields'], 'name');
        $collection = $app->collections->save(Http::body(), $id);
        $removed = array_values(array_diff($before, array_column($collection['fields'], 'name')));
        $log($user, 'collection.updated', $id, $removed ? ['removed_fields' => $removed] : []);
        Http::json($collection);
    });

    $router->add('DELETE', '/admin/api/collections/{id}', function (string $id) use ($app, $auth, $log) {
        $user = $auth->require('admin');
        $app->collections->delete($id);
        $log($user, 'collection.deleted', $id);
        Http::json(['deleted' => $id]);
    });

    $router->add('GET', '/admin/api/collections/{id}/usage', function (string $id) use ($app, $auth) {
        $auth->require('admin');
        Http::json($app->collections->usage($id));
    });

    $router->add('GET', '/admin/api/points', function () use ($app, $auth) {
        $auth->require();
        $page = max(1, (int) (Http::query('page') ?? 1));
        $status = Points::status(Http::query('status') ?? 'pending');
        Http::json($app->points->list(Http::query('collection'), $status, $page, 20));
    });

    $router->add('GET', '/admin/api/points/{id}', function (string $id) use ($app, $auth) {
        $auth->require();
        Http::json($app->points->find((int) $id));
    });

    $router->add('POST', '/admin/api/points', function () use ($app, $auth, $log) {
        $user = $auth->require();
        [$data, $files] = Http::input();
        $collection = $app->collections->get((string) ($data['collection'] ?? ''));
        $position = Points::position($data);
        $status = Points::status($data['status'] ?? 'published');
        $properties = $app->collections->validate($collection, (array) ($data['properties'] ?? []), $files);
        $id = $app->points->create($collection['id'], $position, $properties, $status);
        $log($user, 'point.created', "{$collection['id']} n° $id", ['status' => $status]);
        Http::json($app->points->find($id), 201);
    });

    // POST et non PATCH : PHP ne lit les fichiers envoyés qu'en POST.
    $router->add('POST', '/admin/api/points/{id}', function (string $id) use ($app, $auth, $log) {
        $user = $auth->require();
        $point = $app->points->find((int) $id);
        $collection = $app->collections->get($point['collection']);
        [$data, $files] = Http::input();
        $position = Points::position($data);
        $status = Points::status($data['status'] ?? $point['status']);
        $properties = $app->collections->validate($collection, (array) ($data['properties'] ?? []), $files, $point['properties']);
        $app->points->update($point['id'], $position, $properties, $status);
        $log($user, 'point.updated', "{$point['collection']} n° {$point['id']}", $status !== $point['status'] ? ['from' => $point['status'], 'to' => $status] : []);

        foreach (Collections::imageFields($collection) as $name) {
            $app->images->delete(array_diff($point['properties'][$name] ?? [], $properties[$name] ?? []));
        }
        Http::json($app->points->find($point['id']));
    });

    $router->add('POST', '/admin/api/points/{id}/status', function (string $id) use ($app, $auth, $log) {
        $user = $auth->require();
        $point = $app->points->find((int) $id);
        $status = Points::status(Http::body()['status'] ?? null);
        $app->points->update($point['id'], [$point['lat'], $point['lng']], $point['properties'], $status);
        $log($user, 'point.status', "{$point['collection']} n° {$point['id']}", ['from' => $point['status'], 'to' => $status]);
        Http::json($app->points->find($point['id']));
    });

    $router->add('DELETE', '/admin/api/points/{id}', function (string $id) use ($app, $auth, $log) {
        $user = $auth->require();
        $point = $app->points->find((int) $id);
        $app->edits->discardForPoint($point);
        $app->points->delete($point['id']);
        $log($user, 'point.deleted', "{$point['collection']} n° {$point['id']}");
        foreach (Collections::imageFields($app->collections->get($point['collection'])) as $name) {
            $app->images->delete($point['properties'][$name] ?? []);
        }
        Http::json(['deleted' => $point['id']]);
    });

    // Propositions de modification envoyées par l'API publique

    $router->add('GET', '/admin/api/edits', function () use ($app, $auth) {
        $auth->require();
        $list = $app->edits->list(max(1, (int) (Http::query('page') ?? 1)), 20);
        $list['items'] = array_map(fn ($edit) => $edit + ['point' => $app->points->find($edit['point_id'])], $list['items']);
        Http::json($list);
    });

    $router->add('GET', '/admin/api/edits/{id}', function (string $id) use ($app, $auth) {
        $auth->require();
        $edit = $app->edits->find((int) $id);
        Http::json($edit + ['point' => $app->points->find($edit['point_id'])]);
    });

    $router->add('POST', '/admin/api/edits/{id}/apply', function (string $id) use ($app, $auth, $log) {
        $user = $auth->require();
        $point = $app->points->find($app->edits->find((int) $id)['point_id']);
        $app->edits->apply((int) $id);
        $log($user, 'edit.applied', "{$point['collection']} n° {$point['id']}");
        Http::json(['applied' => (int) $id]);
    });

    $router->add('POST', '/admin/api/edits/{id}/reject', function (string $id) use ($app, $auth, $log) {
        $user = $auth->require();
        $point = $app->points->find($app->edits->find((int) $id)['point_id']);
        $app->edits->reject((int) $id);
        $log($user, 'edit.rejected', "{$point['collection']} n° {$point['id']}");
        Http::json(['rejected' => (int) $id]);
    });

    // Membres (administrateurs)

    $router->add('GET', '/admin/api/members', function () use ($app, $auth) {
        $auth->require('admin');
        Http::json($app->users->members());
    });

    $router->add('PATCH', '/admin/api/members/{id}', function (string $id) use ($app, $auth, $log) {
        $user = $auth->require('admin');
        $role = (string) (Http::body()['role'] ?? '');
        $app->users->setRole((int) $id, $role);
        $log($user, 'member.role', $app->users->find((int) $id)['email'] ?? "n° $id", ['role' => $role]);
        Http::json($app->users->members());
    });

    $router->add('DELETE', '/admin/api/members/{id}', function (string $id) use ($app, $auth, $log) {
        $user = $auth->require('admin');
        if ((int) $id === $user['id']) {
            throw new HttpError(409, 'Vous ne pouvez pas vous retirer vous-même.');
        }
        $email = $app->users->find((int) $id)['email'] ?? "n° $id";
        $app->users->delete((int) $id);
        $log($user, 'member.removed', $email);
        Http::json($app->users->members());
    });

    // Le membre reconfigurera sa double authentification à sa prochaine connexion (téléphone perdu…).
    $router->add('POST', '/admin/api/members/{id}/reset-two-factor', function (string $id) use ($app, $auth, $log) {
        $user = $auth->require('admin');
        if ((int) $id === $user['id']) {
            throw new HttpError(409, 'Utilisez vos codes de secours, ou demandez à un autre compte admin.');
        }
        $app->users->resetTwoFactor((int) $id);
        $log($user, 'member.two_factor_reset', $app->users->find((int) $id)['email'] ?? "n° $id");
        Http::json($app->users->members());
    });

    $router->add('POST', '/admin/api/invitations', function () use ($app, $auth, $log) {
        $user = $auth->require('admin');
        $data = Http::body();
        $token = $app->users->invite($data['email'] ?? '', $data['role'] ?? '');
        $log($user, 'invitation.created', trim((string) $data['email']), ['role' => $data['role']]);
        Http::json(['token' => $token], 201);
    });

    $router->add('DELETE', '/admin/api/invitations/{id}', function (string $id) use ($app, $auth, $log) {
        $user = $auth->require('admin');
        $app->users->deleteInvitation((int) $id);
        $log($user, 'invitation.cancelled', "n° $id");
        Http::json($app->users->members());
    });

    // Site public (administrateurs)

    $router->add('GET', '/admin/api/front', function () use ($app, $auth) {
        $auth->require('admin');
        Http::json($app->front->get());
    });

    $router->add('PUT', '/admin/api/front', function () use ($app, $auth, $log) {
        $user = $auth->require('admin');
        $settings = $app->front->save(Http::body(), array_column($app->collections->all(), 'id'));
        $log($user, 'front.updated', '', ['enabled' => $settings['enabled']]);
        Http::json($settings);
    });

    // Clés d'API (administrateurs)

    $router->add('GET', '/admin/api/keys', function () use ($app, $auth) {
        $auth->require('admin');
        Http::json(['keys' => $app->apiKeys->all(), 'required' => (bool) ($app->config['api_key'] ?? false)]);
    });

    $router->add('POST', '/admin/api/keys', function () use ($app, $auth, $log) {
        $user = $auth->require('admin');
        $data = Http::body();
        $key = $app->apiKeys->create($data['name'] ?? '', $data['domains'] ?? []);
        $log($user, 'key.created', trim((string) $data['name']), ['prefix' => substr($key, 0, 10)]);
        Http::json(['key' => $key], 201);
    });

    $router->add('DELETE', '/admin/api/keys/{id}', function (string $id) use ($app, $auth, $log) {
        $user = $auth->require('admin');
        $name = array_column($app->apiKeys->all(), 'name', 'id')[(int) $id] ?? "n° $id";
        $app->apiKeys->delete((int) $id);
        $log($user, 'key.revoked', $name);
        Http::json(['deleted' => (int) $id]);
    });

    // Journal des actions (administrateurs)

    $router->add('GET', '/admin/api/audit', function () use ($app, $auth) {
        $auth->require('admin');
        Http::json($app->audit->list(max(1, (int) (Http::query('page') ?? 1)), 50));
    });
};
