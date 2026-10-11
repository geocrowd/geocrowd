<?php

namespace Geocrowd;

use PDO;

/**
 * Réglages d'envoi d'e-mails saisis dans le back-office (page Membres), enregistrés en base.
 * La clé 'mail' de config.php, si elle est renseignée, l'emporte. Le mot de passe SMTP est
 * chiffré (voir Secrets) et n'est jamais renvoyé au navigateur.
 */
final class MailSettings
{
    private const KEY = 'mail';
    // Empreinte de la configuration avec laquelle un e-mail de test a été envoyé.
    private const TESTED_KEY = 'mail_tested';
    private const SECURE = ['tls', 'ssl', 'none'];

    public function __construct(private readonly PDO $pdo, private readonly Secrets $secrets, private readonly ?array $config)
    {
    }

    /** Origine de la configuration en vigueur : 'config' (config.php), 'settings' (back-office) ou null. */
    public function source(): ?string
    {
        return $this->config ? 'config' : ($this->stored() ? 'settings' : null);
    }

    /** Configuration à passer au Mailer, mot de passe déchiffré. */
    public function forMailer(): ?array
    {
        if ($this->config) {
            return $this->config;
        }
        $stored = $this->stored();
        if (!$stored) {
            return null;
        }
        if (isset($stored['smtp']['password'])) {
            try {
                $stored['smtp']['password'] = $this->secrets->decrypt($stored['smtp']['password']);
            } catch (\RuntimeException) {
                // Clé de chiffrement remplacée : le mot de passe est à ressaisir.
                $stored['smtp']['password'] = '';
            }
        }
        return $stored;
    }

    /** Réglages affichés dans le back-office, sans le mot de passe. */
    public function forDisplay(): array
    {
        $mail = $this->config ?? $this->stored() ?? [];
        $smtp = $mail['smtp'] ?? null;
        return [
            'source' => $this->source(),
            'from' => $mail['from'] ?? '',
            'from_name' => $mail['from_name'] ?? '',
            // Sans configuration, le formulaire propose un serveur SMTP.
            'transport' => $mail && empty($smtp['host']) ? 'mail' : 'smtp',
            'host' => $smtp['host'] ?? '',
            'port' => $smtp['port'] ?? null,
            'secure' => $smtp['secure'] ?? 'tls',
            'user' => $smtp['user'] ?? '',
            'password_set' => !empty($smtp['password']),
            'tested' => $this->tested(),
        ];
    }

    /**
     * Un e-mail de test a-t-il été envoyé avec la configuration en vigueur ? Toute modification
     * (back-office ou config.php) change l'empreinte et demande un nouveau test.
     */
    public function tested(): bool
    {
        $fingerprint = $this->fingerprint();
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([self::TESTED_KEY]);
        return $fingerprint !== null && hash_equals((string) $stmt->fetchColumn(), $fingerprint);
    }

    public function markTested(): void
    {
        $this->pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value')
            ->execute([self::TESTED_KEY, (string) $this->fingerprint()]);
    }

    private function fingerprint(): ?string
    {
        // Réglages du back-office : le mot de passe y est chiffré avec un vecteur aléatoire,
        // chaque enregistrement change donc l'empreinte.
        $raw = $this->config ? json_encode($this->config) : ($this->stored() ? json_encode($this->stored()) : null);
        return $raw === null ? null : $this->secrets->mac($raw);
    }

    /**
     * Enregistre les réglages saisis. Un mot de passe vide garde le mot de passe enregistré
     * (le navigateur ne le connaît pas).
     */
    public function save(mixed $input): void
    {
        if ($this->config) {
            throw new HttpError(409, 'L\'envoi d\'e-mails est configuré dans config.php : modifiez-le là.');
        }
        $input = is_array($input) ? $input : [];
        $errors = [];

        $from = trim((string) ($input['from'] ?? ''));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $errors['from'] = 'Adresse e-mail invalide.';
        }
        $name = trim((string) ($input['from_name'] ?? ''));
        if (mb_strlen($name) > 100 || preg_match('/[\r\n]/', $name)) {
            $errors['from_name'] = '100 caractères maximum, sur une ligne.';
        }

        $settings = ['from' => $from, 'from_name' => $name ?: 'geocrowd'];
        if (($input['transport'] ?? 'smtp') === 'smtp') {
            $host = strtolower(trim((string) ($input['host'] ?? '')));
            if (!preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $host)) {
                $errors['host'] = 'Nom de serveur invalide (ex. : mail.infomaniak.com).';
            }
            $secure = in_array($input['secure'] ?? null, self::SECURE, true) ? $input['secure'] : 'tls';
            $port = $input['port'] ?? null;
            $port = $port === null || $port === '' ? ($secure === 'ssl' ? 465 : 587) : $port;
            if (!is_int($port) || $port < 1 || $port > 65535) {
                $errors['port'] = 'Port entre 1 et 65535.';
            }
            $user = trim((string) ($input['user'] ?? ''));
            if (mb_strlen($user) > 200 || preg_match('/[\r\n]/', $user)) {
                $errors['user'] = '200 caractères maximum, sur une ligne.';
            }
            $password = (string) ($input['password'] ?? '');
            if (mb_strlen($password) > 500 || preg_match('/[\r\n]/', $password)) {
                $errors['password'] = '500 caractères maximum, sur une ligne.';
            }
            $previous = $this->stored()['smtp']['password'] ?? null;
            $settings['smtp'] = ['host' => $host, 'port' => $port, 'secure' => $secure, 'user' => $user]
                + ($password !== '' ? ['password' => $this->secrets->encrypt($password)] : ($user !== '' && $previous ? ['password' => $previous] : []));
        }

        if ($errors) {
            throw new HttpError(422, 'Certains réglages sont invalides.', $errors);
        }
        $this->pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value')
            ->execute([self::KEY, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /** Désactive l'envoi d'e-mails configuré dans le back-office. */
    public function clear(): void
    {
        if ($this->config) {
            throw new HttpError(409, 'L\'envoi d\'e-mails est configuré dans config.php : modifiez-le là.');
        }
        $this->pdo->prepare('DELETE FROM settings WHERE key = ?')->execute([self::KEY]);
    }

    private function stored(): ?array
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([self::KEY]);
        $value = json_decode((string) $stmt->fetchColumn(), true);
        return is_array($value) ? $value : null;
    }
}
