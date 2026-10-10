<?php

namespace Geocrowd;

use PDO;

/** Membres du back-office, double authentification et invitations. */
final class Users
{
    public const ROLES = ['admin', 'moderator'];
    // Règle de l'instance : double authentification facultative, exigée des comptes admin, ou de tous.
    public const POLICIES = ['optional', 'admins', 'all'];
    private const MIN_PASSWORD = 10;
    private const RECOVERY_CODES = 10;
    // Sans 0/o, 1/l/i : les codes de secours se recopient à la main.
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const COLUMNS = 'id, email, name, role, last_login_at, two_factor_method, two_factor_method IS NOT NULL AS two_factor';

    public function __construct(private readonly PDO $pdo, private readonly int $invitationDays, private readonly Secrets $secrets)
    {
    }

    /** Chiffre les secrets de double authentification enregistrés en clair par une version antérieure. */
    public function encryptLegacySecrets(): void
    {
        $update = $this->pdo->prepare('UPDATE users SET totp_secret = ? WHERE id = ?');
        foreach ($this->pdo->query('SELECT id, totp_secret FROM users WHERE totp_secret IS NOT NULL')->fetchAll() as $row) {
            if (!Secrets::isEncrypted($row['totp_secret'])) {
                $update->execute([$this->secrets->encrypt($row['totp_secret']), $row['id']]);
            }
        }
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ? self::hydrate($user) : null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([trim($email)]);
        $id = $stmt->fetchColumn();
        return $id ? $this->find($id) : null;
    }

    public function create(string $email, string $name, string $password, string $role): int
    {
        $email = self::email($email);
        $this->ensureFree($email);
        $this->pdo->prepare('INSERT INTO users (email, name, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$email, self::name($name), password_hash(self::password($password), PASSWORD_DEFAULT), self::role($role), Db::now()]);
        return (int) $this->pdo->lastInsertId();
    }

    public function authenticate(string $email, string $password): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([trim($email)]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return null;
        }
        return $this->find($row['id']);
    }

    public function touch(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([Db::now(), $id]);
    }

    /** Membres puis invitations en cours. */
    public function members(): array
    {
        $users = array_map(
            fn ($user) => self::hydrate($user) + ['two_factor_required' => $this->requiresTwoFactor($user)],
            $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM users ORDER BY name')->fetchAll(),
        );
        $stmt = $this->pdo->prepare('SELECT id, email, role, expires_at FROM invitations WHERE expires_at > ? ORDER BY id DESC');
        $stmt->execute([Db::now()]);
        return ['users' => $users, 'invitations' => $stmt->fetchAll()];
    }

    public function setRole(int $id, string $role): void
    {
        $role = self::role($role);
        if ($role !== 'admin') {
            $this->ensureNotLastAdmin($id);
        }
        $this->pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
    }

    /**
     * Active la double authentification avec un secret dont le premier code a été vérifié
     * au pas de temps $step, et renvoie de nouveaux codes de secours.
     */
    public function enableTwoFactor(int $id, string $secret, int $step): array
    {
        $this->pdo->prepare("UPDATE users SET two_factor_method = 'totp', totp_secret = ?, totp_last_step = ? WHERE id = ?")
            ->execute([$this->secrets->encrypt($secret), $step, $id]);
        return $this->newRecoveryCodes($id);
    }

    /** Active la double authentification par code envoyé par e-mail (adresse déjà vérifiée par un premier code). */
    public function enableEmailTwoFactor(int $id): array
    {
        $this->pdo->prepare("UPDATE users SET two_factor_method = 'email', totp_secret = NULL, totp_last_step = NULL WHERE id = ?")->execute([$id]);
        return $this->newRecoveryCodes($id);
    }

    /**
     * Désactive la double authentification (et efface les codes de secours) ; si la règle de
     * l'instance l'exige, elle sera reconfigurée à la prochaine connexion.
     */
    public function resetTwoFactor(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET two_factor_method = NULL, totp_secret = NULL, totp_last_step = NULL WHERE id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$id]);
    }

    /**
     * Vérifie un code de l'application, ou à défaut un code de secours (consommé).
     * Un code de l'application déjà utilisé est refusé. Pour la méthode par e-mail, seul un
     * code de secours est vérifié ici (le code envoyé est gardé dans la session, voir Auth).
     */
    public function checkCode(int $id, string $code, bool $allowRecovery = true): bool
    {
        $stmt = $this->pdo->prepare('SELECT totp_secret, totp_last_step FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $code = strtolower(preg_replace('/[\s-]/', '', $code));
        if (!$row || $row['totp_secret'] === null) {
            return $row && $allowRecovery && $this->useRecoveryCode($id, $code);
        }

        try {
            $secret = $this->secrets->decrypt($row['totp_secret']);
        } catch (\RuntimeException) {
            // Clé de chiffrement perdue : les codes de secours, stockés à part, restent utilisables.
            if ($allowRecovery && $this->useRecoveryCode($id, $code)) {
                return true;
            }
            throw new HttpError(500, 'Double authentification illisible : la clé de chiffrement (secret.php) a été perdue ou remplacée. Utilisez un code de secours, ou demandez à un compte admin de la réinitialiser (page Membres ou bin/reset-2fa.php).');
        }
        $step = Totp::verify($secret, $code);
        if ($step !== null) {
            // Mise à jour conditionnelle : deux requêtes simultanées ne peuvent pas utiliser le même code.
            $update = $this->pdo->prepare('UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)');
            $update->execute([$step, $id, $step]);
            return $update->rowCount() === 1;
        }

        return $allowRecovery && $this->useRecoveryCode($id, $code);
    }

    /** Consomme un code de secours s'il est valide. */
    private function useRecoveryCode(int $id, string $code): bool
    {
        $delete = $this->pdo->prepare('DELETE FROM recovery_codes WHERE user_id = ? AND code_hash = ?');
        $delete->execute([$id, hash('sha256', $code)]);
        return $delete->rowCount() === 1;
    }

    public function policy(): string
    {
        $stmt = $this->pdo->query("SELECT value FROM settings WHERE key = 'two_factor_policy'");
        $policy = $stmt->fetchColumn();
        return in_array($policy, self::POLICIES, true) ? $policy : 'optional';
    }

    public function setPolicy(mixed $policy): string
    {
        if (!in_array($policy, self::POLICIES, true)) {
            throw new HttpError(422, 'Règle invalide.');
        }
        $this->pdo->prepare("INSERT INTO settings (key, value) VALUES ('two_factor_policy', ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value")
            ->execute([$policy]);
        return $policy;
    }

    /** La règle de l'instance exige-t-elle la double authentification de ce membre ? */
    public function requiresTwoFactor(array $user): bool
    {
        $policy = $this->policy();
        return $policy === 'all' || ($policy === 'admins' && $user['role'] === 'admin');
    }

    public function recoveryCount(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    /** Remplace les codes de secours d'un membre et les renvoie (affichés une seule fois). */
    public function newRecoveryCodes(int $id): array
    {
        $this->pdo->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$id]);
        $insert = $this->pdo->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)');
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $insert->execute([$id, hash('sha256', $code)]);
            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
        }
        return $codes;
    }

    public function delete(int $id): void
    {
        $this->ensureNotLastAdmin($id);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    /** Crée une invitation et renvoie son jeton (affiché une seule fois). */
    public function invite(string $email, string $role): string
    {
        $email = self::email($email);
        $this->ensureFree($email);
        $token = bin2hex(random_bytes(24));
        $this->pdo->prepare('INSERT INTO invitations (token_hash, email, role, created_at, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $token), $email, self::role($role), Db::now(), gmdate('Y-m-d\TH:i:s\Z', time() + $this->invitationDays * 86400)]);
        return $token;
    }

    public function invitation(string $token): array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, role FROM invitations WHERE token_hash = ? AND expires_at > ?');
        $stmt->execute([hash('sha256', $token), Db::now()]);
        return $stmt->fetch() ?: throw new HttpError(404, 'Invitation invalide ou expirée.');
    }

    public function accept(string $token, string $name, string $password): int
    {
        $invitation = $this->invitation($token);
        $this->pdo->beginTransaction();
        try {
            $this->deleteInvitation($invitation['id']);
            $id = $this->create($invitation['email'], $name, $password, $invitation['role']);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deleteInvitation(int $id): void
    {
        $this->pdo->prepare('DELETE FROM invitations WHERE id = ?')->execute([$id]);
    }

    private function ensureFree(string $email): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            throw new HttpError(409, 'Un membre utilise déjà cette adresse.');
        }
    }

    private function ensureNotLastAdmin(int $id): void
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND id != ?");
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new HttpError(409, 'Il doit rester au moins un compte admin.');
        }
    }

    private static function hydrate(array $user): array
    {
        return ['two_factor' => (bool) $user['two_factor']] + $user;
    }

    private static function email(mixed $email): string
    {
        $email = is_string($email) ? trim($email) : '';
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : throw new HttpError(422, 'Adresse e-mail invalide.', ['email' => 'Adresse invalide.']);
    }

    private static function name(mixed $name): string
    {
        $name = is_string($name) ? trim($name) : '';
        return $name !== '' && mb_strlen($name) <= 100 ? $name : throw new HttpError(422, 'Nom invalide.', ['name' => 'Entre 1 et 100 caractères.']);
    }

    private static function password(mixed $password): string
    {
        return is_string($password) && mb_strlen($password) >= self::MIN_PASSWORD
            ? $password
            : throw new HttpError(422, 'Mot de passe trop court.', ['password' => self::MIN_PASSWORD . ' caractères minimum.']);
    }

    private static function role(mixed $role): string
    {
        return in_array($role, self::ROLES, true) ? $role : throw new HttpError(422, 'Rôle invalide.');
    }
}
