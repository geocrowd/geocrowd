<?php

namespace Geocrowd;

/**
 * Session du back-office, rôles et protection CSRF.
 * Pour un membre qui a activé la double authentification (ou à qui la règle de l'instance
 * l'impose), la connexion se fait en deux temps : le mot de passe ouvre une étape « en attente »
 * de quelques minutes, que seul un code (application ou e-mail) transforme en session.
 */
final class Auth
{
    private const PENDING_SECONDS = 600;
    private const EMAIL_CODE_SECONDS = 600;
    // Après confirmation du mot de passe, durée pendant laquelle le second facteur peut être reconfiguré.
    private const REAUTH_SECONDS = 300;

    public function __construct(private readonly Users $users)
    {
        session_name('geocrowd');
        session_set_cookie_params([
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Strict',
        ]);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    /**
     * Membre connecté, relu en base pour suivre un changement de rôle, une suppression, une
     * réinitialisation de sa double authentification ou un changement de la règle de l'instance.
     */
    public function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        $user = $id ? $this->users->find($id) : null;
        return $user && ($user['two_factor'] || !$this->users->requiresTwoFactor($user)) ? $user : null;
    }

    /** Membre dont le mot de passe a été vérifié et qui doit encore saisir un code. */
    public function pending(): ?array
    {
        $pending = $_SESSION['pending'] ?? null;
        if (!$pending || $pending['at'] < time() - self::PENDING_SECONDS) {
            return null;
        }
        return $this->users->find($pending['id']);
    }

    /** Mot de passe vérifié : la double authentification reste à faire (ou à configurer). */
    public function passwordChecked(array $user): void
    {
        session_regenerate_id(true);
        unset($_SESSION['user_id']);
        $_SESSION['pending'] = ['id' => $user['id'], 'at' => time()];
    }

    /** Secret proposé pendant la configuration, conservé jusqu'à la vérification du premier code. */
    public function enrollmentSecret(): string
    {
        return $_SESSION['enroll_secret'] ??= Totp::secret();
    }

    /**
     * Nouveau code à 6 chiffres à envoyer par e-mail, gardé haché dans la session.
     * $purpose : 'login' (connexion) ou 'enroll' (activation de la méthode par e-mail).
     */
    public function newEmailCode(array $user, string $purpose): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['email_code'] = [
            'user' => $user['id'],
            'purpose' => $purpose,
            'hash' => hash('sha256', $code),
            'sent' => time(),
        ];
        return $code;
    }

    /** Envoi échoué : le code n'est jamais parti, un nouvel envoi est permis tout de suite. */
    public function cancelEmailCode(): void
    {
        unset($_SESSION['email_code']);
    }

    /** Vérifie (et consomme) le code envoyé par e-mail. */
    public function checkEmailCode(array $user, string $code, string $purpose): bool
    {
        $entry = $_SESSION['email_code'] ?? null;
        $valid = $entry && $entry['user'] === $user['id'] && $entry['purpose'] === $purpose
            && $entry['sent'] > time() - self::EMAIL_CODE_SECONDS
            && hash_equals($entry['hash'], hash('sha256', preg_replace('/\s/', '', $code)));
        if ($valid) {
            unset($_SESSION['email_code']);
        }
        return $valid;
    }

    /** Fin d'une configuration de double authentification. */
    public function enrolled(): void
    {
        unset($_SESSION['enroll_secret'], $_SESSION['email_code'], $_SESSION['reauth']);
    }

    /** Mot de passe redemandé et vérifié : le second facteur peut être reconfiguré quelques minutes. */
    public function passwordConfirmed(): void
    {
        $_SESSION['reauth'] = time();
    }

    public function recentlyConfirmed(): bool
    {
        return ($_SESSION['reauth'] ?? 0) > time() - self::REAUTH_SECONDS;
    }

    public function login(array $user): void
    {
        session_regenerate_id(true);
        unset($_SESSION['pending'], $_SESSION['email_code'], $_SESSION['enroll_secret']);
        $_SESSION['user_id'] = $user['id'];
        $this->users->touch($user['id']);
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public function csrf(): string
    {
        return $_SESSION['csrf'];
    }

    public function checkCsrf(): void
    {
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            throw new HttpError(403, 'Jeton de sécurité invalide, rechargez la page.');
        }
    }

    /** Membre connecté ayant au moins le rôle demandé. */
    public function require(string $role = 'moderator'): array
    {
        $user = $this->user() ?? throw new HttpError(401, 'Connexion requise.');
        if ($role === 'admin' && $user['role'] !== 'admin') {
            throw new HttpError(403, 'Réservé aux admins.');
        }
        return $user;
    }
}
