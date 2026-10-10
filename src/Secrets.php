<?php

namespace Geocrowd;

/**
 * Chiffrement des données sensibles stockées en base (secrets de double authentification).
 * La clé est dans un fichier PHP à part : demandé par le web, il s'exécute sans rien afficher,
 * si bien qu'une base téléchargée par erreur ne livre pas les secrets. Il faut le sauvegarder
 * avec la base : sans lui, les secrets chiffrés sont perdus (voir bin/reset-2fa.php).
 */
final class Secrets
{
    private const PREFIX = 'v1:';
    private const CIPHER = 'aes-256-gcm';

    private ?string $key = null;

    public function __construct(private readonly string $file)
    {
    }

    public function encrypt(string $plain): string
    {
        if (!function_exists('openssl_encrypt')) {
            // Sans l'extension openssl, la valeur reste en clair (comme avant le chiffrement).
            return $plain;
        }
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** Valeur déchiffrée ; une valeur sans préfixe est une ancienne valeur en clair. */
    public function decrypt(string $value): string
    {
        if (!self::isEncrypted($value)) {
            return $value;
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)));
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? throw new \RuntimeException('Secret illisible : la clé ' . $this->file . ' a changé.') : $plain;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /** Clé lue dans le fichier, créé au premier usage (création exclusive : pas de clé écrasée). */
    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        if (!is_file($this->file)) {
            if (!is_dir(dirname($this->file))) {
                mkdir(dirname($this->file), 0750, true);
            }
            $handle = @fopen($this->file, 'x');
            if ($handle) {
                fwrite($handle, "<?php\n\n// Clé de chiffrement de geocrowd : à sauvegarder avec la base, à ne jamais publier.\nreturn '" . bin2hex(random_bytes(32)) . "';\n");
                fclose($handle);
                @chmod($this->file, 0600);
            }
        }
        $key = require $this->file;
        if (!is_string($key) || strlen($key) !== 64) {
            throw new \RuntimeException('Clé de chiffrement invalide : ' . $this->file);
        }
        return $this->key = hex2bin($key);
    }
}
